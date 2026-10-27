<?php

namespace Modules\Recruitment\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Recruitment\Exceptions\RecruitmentException;
use Modules\Recruitment\Models\Application;
use Modules\Recruitment\Models\Candidate;
use Modules\Recruitment\Models\Offer;
use Modules\Recruitment\Models\Opening;

/**
 * Job offers. HR writes one for an application still going (one live offer
 * at a time): the salary, the first day, the day to answer by. Sending it
 * needs someone else who may hire to approve first when the rule
 * recruitment.offer_needs_approval says so (a salary is money: never the
 * writer). Sent, the letter is filled in and kept in the candidate's
 * language and they get a link (only its hash kept) to accept or decline.
 * After the day to answer by, it expires. Every step is audited; hiring
 * stays HR's own step.
 */
class Offers
{
    public function __construct(
        private Place $place,
        private LetterTemplates $letters,
        private Careers $careers,
        private OrganizationSettingsResolver $settings,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private Recipients $recipients,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * A new draft, or changes to a draft with the version seen.
     *
     * @param  array{salary_minor: int, start_on: string, respond_by: string, base_version?: int}  $data
     */
    public function save(Organization $company, Application $application, ?Offer $offer, array $data, User $actor): Offer
    {
        $this->checkDays($company, $data['start_on'], $data['respond_by']);

        return $this->place->transaction($company, function () use ($company, $application, $offer, $data, $actor) {
            if ($offer === null) {
                $application = $this->place->query(Application::class, $company)->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
                if ($application->status !== Application::ACTIVE) {
                    throw RecruitmentException::finished();
                }
                if ($this->place->query(Offer::class, $company)->where('application_id', $application->getKey())->whereIn('status', Offer::LIVE)->exists()) {
                    throw RecruitmentException::offerExists();
                }
                $offer = new Offer([
                    'organization_id' => $company->getKey(), 'application_id' => $application->getKey(), 'status' => Offer::DRAFT,
                    'currency' => (string) ($this->settings->values($company)['currency_code'] ?? 'BDT'), 'created_by' => $actor->getKey(), 'version' => 0,
                ]);
            } else {
                $offer = $this->fresh($company, $offer, (int) $data['base_version']);
                if ($offer->status !== Offer::DRAFT) {
                    throw RecruitmentException::finished();
                }
            }
            $offer->fill(['salary_minor' => $data['salary_minor'], 'start_on' => $data['start_on'], 'respond_by' => $data['respond_by']]);
            $offer->forceFill(['version' => $offer->version + 1])->save();
            $this->audit->record($offer->version === 1 ? 'recruitment.offer_written' : 'recruitment.offer_changed', $offer, new: $this->values($offer), actor: $actor, organizationId: $company->getKey());

            return $offer;
        });
    }

    /**
     * send (a draft: to the approvers when the rule asks, else to the candidate) | approve (someone else who
     * may hire: sent) | return (back to a draft, with a note) | withdraw (not answered yet).
     */
    public function step(Organization $company, Offer $offer, string $step, int $baseVersion, ?string $note, User $actor): Offer
    {
        [$offer, $tell] = $this->place->transaction($company, function () use ($company, $offer, $step, $baseVersion, $note, $actor) {
            $offer = $this->fresh($company, $offer, $baseVersion);
            $application = $this->place->query(Application::class, $company)->whereKey($offer->application_id)->firstOrFail();
            $from = $offer->status;
            $tell = null;
            switch ($step) {
                case 'send':
                    $this->expect($offer, [Offer::DRAFT]);
                    if ($application->status !== Application::ACTIVE) {
                        throw RecruitmentException::finished();
                    }
                    $this->checkDays($company, $offer->start_on->toDateString(), $offer->respond_by->toDateString());
                    if ((bool) $this->rules->get('recruitment.offer_needs_approval', $this->contexts->forOrganization($company))) {
                        $offer->forceFill(['status' => Offer::PENDING, 'note' => null]);
                        $tell = 'approvers';
                    } else {
                        $tell = $this->deliver($company, $offer, $application);
                    }
                    break;
                case 'approve':
                    $this->expect($offer, [Offer::PENDING]);
                    if ($offer->created_by === $actor->getKey()) {
                        throw RecruitmentException::ownOffer();
                    }
                    $offer->approved_by = $actor->getKey();
                    $tell = $this->deliver($company, $offer, $application);
                    break;
                case 'return':
                    $this->expect($offer, [Offer::PENDING]);
                    $offer->forceFill(['status' => Offer::DRAFT, 'note' => $note]);
                    break;
                default: // withdraw
                    $this->expect($offer, [Offer::DRAFT, Offer::PENDING, Offer::SENT]);
                    $offer->forceFill(['status' => Offer::WITHDRAWN, 'token_hash' => null, 'note' => $note]);
            }
            $offer->forceFill(['version' => $offer->version + 1])->save();
            $this->audit->record("recruitment.offer_{$step}", $offer, old: ['status' => $from], new: $this->values($offer), reason: $note, actor: $actor, organizationId: $company->getKey());

            return [$offer, $tell];
        });

        if ($tell === 'approvers') {
            $this->tellStaff('recruitment.offer_to_approve', $company, $offer, $this->approvers($company, $offer)->reject(fn (User $user) => $user->getKey() === $offer->created_by));
        } elseif (is_string($tell)) {
            $this->tellCandidate($company, $offer, $tell);
        }

        return $offer;
    }

    /**
     * The candidate's answer through their link: accept or decline (an offer past its day to answer by expires
     * instead). The people who wrote and approved it, and those managing recruitment, are told. Null when the
     * link is unknown.
     */
    public function answer(Organization $company, string $token, string $answer): ?Offer
    {
        $offer = $this->place->transaction($company, function () use ($company, $token, $answer) {
            $offer = $this->byToken($company, $token, lock: true);
            if ($offer === null || $offer->status !== Offer::SENT) {
                return $offer;
            }
            if ($this->isPast($company, $offer)) {
                $offer->forceFill(['status' => Offer::EXPIRED, 'token_hash' => null, 'version' => $offer->version + 1])->save();

                return $offer;
            }
            $offer->forceFill(['status' => $answer === 'accept' ? Offer::ACCEPTED : Offer::DECLINED, 'answered_at' => now(), 'version' => $offer->version + 1])->save();
            $this->audit->record("recruitment.offer_{$offer->status}", $offer, new: $this->values($offer), organizationId: $company->getKey());

            return $offer;
        });
        if ($offer !== null && in_array($offer->status, [Offer::ACCEPTED, Offer::DECLINED], true) && $offer->answered_at?->gt(now()->subMinute())) {
            $unit = Organization::query()->find($this->place->query(Application::class, $company)->whereKey($offer->application_id)->value('unit_id'));
            $people = User::query()->whereKey(array_filter([$offer->created_by, $offer->approved_by]))->get();
            if ($unit !== null) {
                $people = $people->merge($this->recipients->holding('recruitment.manage', $unit))->unique(fn (User $user) => $user->getKey());
            }
            $this->tellStaff('recruitment.offer_answered', $company, $offer, $people);
        }

        return $offer;
    }

    /** The offer of a candidate's link (sent, answered or past), or null. */
    public function byToken(Organization $company, string $token, bool $lock = false): ?Offer
    {
        return $this->place->query(Offer::class, $company)->where('token_hash', hash('sha256', $token))
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    /** Offers past their day to answer by expire. How many. */
    public function expire(Organization $company): int
    {
        $past = $this->place->query(Offer::class, $company)->where('status', Offer::SENT)
            ->where('respond_by', '<', $this->place->today($company)->toDateString())->get();
        foreach ($past as $offer) {
            $offer->forceFill(['status' => Offer::EXPIRED, 'token_hash' => null, 'version' => $offer->version + 1])->save();
            $this->audit->record('recruitment.offer_expired', $offer, new: $this->values($offer), organizationId: $company->getKey());
        }

        return $past->count();
    }

    public function isPast(Organization $company, Offer $offer): bool
    {
        return $offer->respond_by->toDateString() < $this->place->today($company)->toDateString();
    }

    /**
     * The letter of an offer in a language with everything filled in (as it is now; a sent offer keeps its own).
     */
    public function letter(Organization $company, Offer $offer, ?string $locale = null): string
    {
        $application = $this->place->query(Application::class, $company)->whereKey($offer->application_id)->firstOrFail();
        $candidate = $this->place->query(Candidate::class, $company)->whereKey($application->candidate_id)->first();
        $opening = $this->place->query(Opening::class, $company)->whereKey($application->opening_id)->first();
        $locale ??= $candidate?->locale ?: app(Notifier::class)->locale($company);

        return $this->letters->render($company, $locale, [
            'candidate.name' => (string) $candidate?->name,
            'job.title' => (string) $opening?->textIn('title', $locale),
            'company.name' => $company->displayName($locale),
            'unit.name' => (string) Organization::query()->find($application->unit_id)?->displayName($locale),
            'salary' => LetterTemplates::money($offer->salary_minor, $offer->currency, $locale),
            'start_date' => LetterTemplates::date($offer->start_on, $locale),
            'respond_by' => LetterTemplates::date($offer->respond_by, $locale),
            'today' => LetterTemplates::date($this->place->today($company), $locale),
        ]);
    }

    /** Whether this person may approve: someone else who may hire where the job is. */
    public function canApprove(Organization $company, Offer $offer, User $user): bool
    {
        return $offer->status === Offer::PENDING && $offer->created_by !== $user->getKey()
            && $this->approvers($company, $offer)->contains(fn (User $person) => $person->getKey() === $user->getKey());
    }

    /** Sent: the letter filled in and kept in the candidate's language, a new link. Returns the link's token. */
    private function deliver(Organization $company, Offer $offer, Application $application): string
    {
        $slug = $this->careers->slug($company) ?? throw RecruitmentException::noCareersAddress();
        $candidate = $this->place->query(Candidate::class, $company)->whereKey($application->candidate_id)->firstOrFail();
        $locale = $candidate->locale ?: app(Notifier::class)->locale($company);
        $token = Str::random(48);
        $offer->forceFill([
            'status' => Offer::SENT, 'letter' => $this->letter($company, $offer, $locale), 'locale' => $locale,
            'token_hash' => hash('sha256', $token), 'sent_at' => now(), 'note' => null,
        ]);

        return "/careers/{$slug}/offer/{$token}?lang={$locale}";
    }

    private function tellCandidate(Organization $company, Offer $offer, string $path): void
    {
        $application = $this->place->query(Application::class, $company)->whereKey($offer->application_id)->firstOrFail();
        $candidate = $this->place->query(Candidate::class, $company)->whereKey($application->candidate_id)->first();
        $opening = $this->place->query(Opening::class, $company)->whereKey($application->opening_id)->first();
        if ($candidate === null) {
            return;
        }
        $this->notifier->notifyContacts('recruitment.offer_sent', [['name' => $candidate->name, 'email' => $candidate->email, 'locale' => $offer->locale]],
            fn (string $language) => [
                'organization' => $company->displayName($language), 'name' => (string) $candidate->name, 'job' => (string) $opening?->textIn('title', $language),
                'respond_by' => LetterTemplates::date($offer->respond_by, $language),
            ], $company->partner, $company, ['mail'], $path);
    }

    private function tellStaff(string $key, Organization $company, Offer $offer, iterable $people): void
    {
        $application = $this->place->query(Application::class, $company)->whereKey($offer->application_id)->first();
        $opening = $application === null ? null : $this->place->query(Opening::class, $company)->whereKey($application->opening_id)->first();
        $people = collect($people)->values();
        if ($people->isEmpty() || $opening === null) {
            return;
        }
        $this->notifier->notify($key, $people, fn (string $language) => [
            'organization' => $company->displayName($language), 'job' => $opening->textIn('title', $language),
            'answer' => __("recruitment::recruitment.offer_status.{$offer->status}", [], $language),
        ], $company->partner, $company, "/recruitment/openings/{$opening->getKey()}");
    }

    /** @return Collection<int, User> */
    private function approvers(Organization $company, Offer $offer)
    {
        $unit = Organization::query()->find($this->place->query(Application::class, $company)->whereKey($offer->application_id)->value('unit_id'));

        return $unit === null ? collect() : $this->recipients->holding('recruitment.hire', $unit);
    }

    private function checkDays(Organization $company, string $startOn, string $respondBy): void
    {
        $today = $this->place->today($company)->toDateString();
        $errors = [];
        if ($respondBy < $today) {
            $errors['respond_by'] = __('recruitment::recruitment.validation.past_day');
        }
        if ($startOn < $respondBy) {
            $errors['start_on'] = __('recruitment::recruitment.validation.start_after_answer');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param list<string> $statuses */
    private function expect(Offer $offer, array $statuses): void
    {
        if (! in_array($offer->status, $statuses, true)) {
            throw RecruitmentException::finished();
        }
    }

    private function fresh(Organization $company, Offer $offer, int $baseVersion): Offer
    {
        /** @var Offer $fresh */
        $fresh = $this->place->query(Offer::class, $company)->whereKey($offer->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw RecruitmentException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Offer $offer): array
    {
        return [
            ...$offer->only(['application_id', 'currency', 'salary_minor', 'status']),
            'start_on' => $offer->start_on?->toDateString(), 'respond_by' => $offer->respond_by?->toDateString(),
        ];
    }
}
