<?php

namespace Modules\Recruitment\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Hrm\Services\EmployeeLifecycle;
use Modules\Recruitment\Exceptions\RecruitmentException;
use Modules\Recruitment\Models\Application;
use Modules\Recruitment\Models\Candidate;
use Modules\Recruitment\Models\Feedback;
use Modules\Recruitment\Models\Interview;
use Modules\Recruitment\Models\Offer;
use Modules\Recruitment\Models\Opening;
use Modules\Recruitment\Models\StageMove;

/**
 * Applications to a company's openings. From the careers page a person
 * applies with a CV and confirms their email from the mail they get (until
 * then nobody at the company sees it; unconfirmed ones go after
 * CONFIRM_HOURS). HR also adds people by hand. An application moves
 * through the company's stages and ends hired (into HRM, through its
 * EmployeeLifecycle), rejected (the person told, if HR wishes) or
 * withdrawn; people not taken are forgotten after
 * recruitment.keep_rejected_days. Every step is kept (rec_stage_moves).
 */
class Applications
{
    /** A confirmation link works this long. */
    public const CONFIRM_HOURS = 48;

    public function __construct(
        private Place $place,
        private Stages $stages,
        private Openings $openings,
        private CvFiles $files,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private Recipients $recipients,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * From the careers page: kept unconfirmed and a confirmation mail sent to the person. Applying again
     * to the same opening before confirming sends a new mail.
     *
     * @param  array{name: string, email: string, phone?: string|null, cover_note?: string|null}  $data
     */
    public function apply(Organization $company, Opening $opening, array $data, UploadedFile $cv, string $locale, string $slug): Application
    {
        if (! $opening->is_public || ! $this->openings->takesApplications($company, $opening)) {
            throw RecruitmentException::notOpen();
        }
        $this->files->check($company, $cv, $locale);
        $hash = Candidate::hashOf($data['email']);
        $perDay = (int) $this->rules->get('recruitment.applications_per_email_day', $this->contexts->forOrganization($company));
        $token = Str::random(48);

        $application = $this->place->transaction($company, function () use ($company, $opening, $data, $cv, $locale, $hash, $perDay, $token) {
            $candidate = $this->place->query(Candidate::class, $company)->where('email_hash', $hash)->lockForUpdate()->first();
            if ($candidate !== null) {
                $today = $this->place->query(Application::class, $company)->where('candidate_id', $candidate->getKey())->where('updated_at', '>=', now()->subDay())->count();
                if ($today >= $perDay) {
                    throw RecruitmentException::tooManyApplications();
                }
            }
            $candidate = $this->candidate($company, $candidate, $data, $locale, consent: true);
            $application = $this->place->query(Application::class, $company)->where('opening_id', $opening->getKey())->where('candidate_id', $candidate->getKey())->first();
            if ($application !== null && $application->status !== Application::UNCONFIRMED) {
                throw RecruitmentException::alreadyApplied();
            }
            $application ??= new Application([
                'organization_id' => $company->getKey(), 'opening_id' => $opening->getKey(), 'unit_id' => $opening->unit_id,
                'candidate_id' => $candidate->getKey(), 'stage' => $this->stages->first($company), 'status' => Application::UNCONFIRMED,
                'source' => 'careers', 'version' => 0,
            ]);
            $application->forceFill([
                'cover_note' => $this->clean($data['cover_note'] ?? null), 'confirm_token_hash' => hash('sha256', $token),
                'confirm_expires_at' => now()->addHours(self::CONFIRM_HOURS), 'version' => $application->version + 1,
            ])->save();
            // A new CV replaces the one sent before confirming.
            $this->files->removeAll($company, $application);
            $this->files->add($company, $application, $cv, null, $locale);

            return [$application, $candidate];
        });
        [$application, $candidate] = $application;

        $this->notifier->notifyContacts('recruitment.confirm', [['name' => $data['name'], 'email' => $candidate->email, 'locale' => $locale]],
            fn (string $language) => [
                'organization' => $company->displayName($language), 'name' => trim($data['name']), 'job' => $opening->textIn('title', $language),
                'hours' => (string) self::CONFIRM_HOURS,
            ], $company->partner, $company, ['mail'], "/careers/{$slug}/confirm/{$token}?lang={$locale}");

        return $application;
    }

    /** The person confirmed their email: the application counts, and the people who handle the opening are told. Null when the link is unknown or too old. */
    public function confirm(Organization $company, string $token): ?Application
    {
        $application = $this->place->transaction($company, function () use ($company, $token) {
            $application = $this->place->query(Application::class, $company)->where('confirm_token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if ($application === null || $application->status !== Application::UNCONFIRMED || $application->confirm_expires_at?->isPast()) {
                return null;
            }
            $application->forceFill([
                'status' => Application::ACTIVE, 'confirmed_at' => now(), 'confirm_token_hash' => null, 'confirm_expires_at' => null,
                'version' => $application->version + 1,
            ])->save();
            $this->move($company, $application, null, $application->stage, null, null);

            return $application;
        });
        if ($application !== null) {
            $opening = $this->place->query(Opening::class, $company)->whereKey($application->opening_id)->first();
            $unit = Organization::query()->find($application->unit_id);
            if ($opening !== null && $unit !== null) {
                $this->notifier->notify('recruitment.applied', $this->recipients->holding('recruitment.manage', $unit),
                    fn (string $language) => ['organization' => $company->displayName($language), 'job' => $opening->textIn('title', $language)],
                    $company->partner, $company, "/recruitment/openings/{$opening->getKey()}");
            }
        }

        return $application;
    }

    /**
     * Someone HR adds by hand (met at a fair, sent by a colleague): counted at once. The same op id is the same application.
     *
     * @param  array{name: string, email: string, phone?: string|null, cover_note?: string|null, source: string, op_id?: string|null}  $data
     */
    public function add(Organization $company, Opening $opening, array $data, User $actor): Application
    {
        if (! empty($data['op_id'])) {
            $same = $this->place->query(Application::class, $company)->where('op_id', $data['op_id'])->first();
            if ($same !== null) {
                return $same;
            }
        }
        if (in_array($opening->status, [Opening::FILLED], true)) {
            throw RecruitmentException::finished();
        }

        return $this->place->transaction($company, function () use ($company, $opening, $data, $actor) {
            $candidate = $this->place->query(Candidate::class, $company)->where('email_hash', Candidate::hashOf($data['email']))->lockForUpdate()->first();
            $candidate = $this->candidate($company, $candidate, $data, null, consent: false);
            if ($this->place->query(Application::class, $company)->where('opening_id', $opening->getKey())->where('candidate_id', $candidate->getKey())->exists()) {
                throw RecruitmentException::alreadyApplied();
            }
            $application = new Application;
            $application->fill([
                'organization_id' => $company->getKey(), 'opening_id' => $opening->getKey(), 'unit_id' => $opening->unit_id,
                'candidate_id' => $candidate->getKey(), 'stage' => $this->stages->first($company), 'status' => Application::ACTIVE,
                'source' => $data['source'], 'cover_note' => $this->clean($data['cover_note'] ?? null), 'confirmed_at' => now(),
                'op_id' => $data['op_id'] ?? null, 'version' => 1,
            ])->save();
            $this->move($company, $application, null, $application->stage, null, $actor);
            $this->audit->record('recruitment.candidate_added', $application, new: $this->values($application), actor: $actor, organizationId: $company->getKey());

            return $application;
        });
    }

    /**
     * Another stage, a rating, or both, with the version seen (a note kept with the move).
     *
     * @param  array{base_version: int, stage?: string|null, rating?: int|null, note?: string|null}  $data
     */
    public function update(Organization $company, Application $application, array $data, User $actor): Application
    {
        $stage = isset($data['stage']) ? $this->stages->check($company, $data['stage']) : null;

        return $this->place->transaction($company, function () use ($company, $application, $data, $stage, $actor) {
            $application = $this->active($company, $application, (int) $data['base_version']);
            $from = $application->stage;
            if (array_key_exists('rating', $data)) {
                $application->rating = $data['rating'];
            }
            if ($stage !== null && $stage !== $from) {
                $application->stage = $stage;
                $this->move($company, $application, $from, $stage, $data['note'] ?? null, $actor);
                $this->audit->record('recruitment.stage_changed', $application, old: ['stage' => $from], new: ['stage' => $stage], reason: $data['note'] ?? null, actor: $actor, organizationId: $company->getKey());
            }
            $application->forceFill(['version' => $application->version + 1])->save();

            return $application;
        });
    }

    /**
     * Ends an application: rejected (the person told when $tell) or withdrawn (they no longer want it).
     * Their interviews still to come are cancelled.
     */
    public function end(Organization $company, Application $application, string $status, int $baseVersion, string $reason, bool $tell, User $actor): Application
    {
        $application = $this->place->transaction($company, function () use ($company, $application, $status, $baseVersion, $reason, $actor) {
            $application = $this->fresh($company, $application, $baseVersion);
            if (! in_array($application->status, [Application::ACTIVE, Application::UNCONFIRMED], true)) {
                throw RecruitmentException::finished();
            }
            $application->forceFill(['status' => $status, 'reject_reason' => $reason, 'decided_at' => now(), 'confirm_token_hash' => null, 'version' => $application->version + 1])->save();
            $this->move($company, $application, $application->stage, $application->stage, $reason, $actor, $status);
            $this->cancelInterviews($company, $application);
            $this->audit->record("recruitment.{$status}", $application, new: $this->values($application), reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $application;
        });
        if ($status === Application::REJECTED && $tell) {
            $this->tellCandidate('recruitment.not_selected', $company, $application);
        }

        return $application;
    }

    /**
     * Hired: the person becomes an employee in HRM (its own checks and rules; their name and contacts from
     * the application, the opening's unit and position), the application ends hired and the opening is
     * filled when everyone needed is.
     *
     * @param  array{base_version: int, joined_on: string, employment_type?: string|null, manager_id?: string|null}  $data
     */
    public function hire(Organization $company, Application $application, array $data, User $actor): Application
    {
        return $this->place->transaction($company, function () use ($company, $application, $data, $actor) {
            $application = $this->active($company, $application, (int) $data['base_version']);
            $opening = $this->place->query(Opening::class, $company)->whereKey($application->opening_id)->lockForUpdate()->firstOrFail();
            if ($opening->status === Opening::FILLED) {
                throw RecruitmentException::finished();
            }
            $candidate = $this->place->query(Candidate::class, $company)->whereKey($application->candidate_id)->firstOrFail();
            $unit = Organization::query()->findOrFail($opening->unit_id);
            $hire = array_filter([
                'full_name' => $candidate->name, 'email' => $candidate->email, 'phone' => $candidate->phone,
                'position_id' => $opening->position_id, 'employment_type' => $data['employment_type'] ?? $opening->employment_type,
                'manager_id' => $data['manager_id'] ?? null, 'joined_on' => $data['joined_on'],
                'reason' => __('recruitment::recruitment.hired_reason', ['job' => $opening->textIn('title')]),
            ], fn ($value) => $value !== null && $value !== '');
            $lifecycle = app(EmployeeLifecycle::class);
            $errors = $lifecycle->hireErrors($unit, $hire);
            if ($errors !== []) {
                throw ValidationException::withMessages(array_map(fn (string $message) => [$message], $errors));
            }
            $employee = $lifecycle->hire($unit, $hire, $actor);

            $application->forceFill(['status' => Application::HIRED, 'employee_id' => $employee->getKey(), 'decided_at' => now(), 'version' => $application->version + 1])->save();
            $this->move($company, $application, $application->stage, $application->stage, null, $actor, Application::HIRED);
            $this->cancelInterviews($company, $application);
            $this->openings->hired($company, $opening);
            $this->audit->record('recruitment.hired', $application, new: [...$this->values($application), 'employee_id' => $employee->getKey()], actor: $actor, organizationId: $company->getKey());

            return $application;
        });
    }

    /**
     * Forgets people: applications never confirmed (after CONFIRM_HOURS) and those rejected or withdrawn
     * longer ago than recruitment.keep_rejected_days, with their files, interviews and feedback; a person
     * with no application left is removed. How many applications went.
     */
    public function forget(Organization $company): int
    {
        $days = (int) $this->rules->get('recruitment.keep_rejected_days', $this->contexts->forOrganization($company));
        $old = $this->place->query(Application::class, $company)
            ->where(fn ($query) => $query->where(fn ($inner) => $inner->where('status', Application::UNCONFIRMED)->where('confirm_expires_at', '<', now()))
                ->orWhere(fn ($inner) => $inner->whereIn('status', [Application::REJECTED, Application::WITHDRAWN])->where('decided_at', '<', now()->subDays($days))))
            ->limit(500)->get();
        foreach ($old as $application) {
            $this->place->transaction($company, function () use ($company, $application) {
                $this->files->removeAll($company, $application);
                $this->place->query(Feedback::class, $company)->where('application_id', $application->getKey())->delete();
                $this->place->query(Offer::class, $company)->where('application_id', $application->getKey())->delete();
                $this->place->query(Interview::class, $company)->where('application_id', $application->getKey())->delete();
                $this->place->query(StageMove::class, $company)->where('application_id', $application->getKey())->delete();
                $application->delete();
                if (! $this->place->query(Application::class, $company)->where('candidate_id', $application->candidate_id)->exists()) {
                    $this->place->query(Candidate::class, $company)->whereKey($application->candidate_id)->delete();
                }
            });
        }
        if ($old->isNotEmpty()) {
            $this->audit->record('recruitment.people_forgotten', $company, new: ['applications' => $old->count()], organizationId: $company->getKey());
        }

        return $old->count();
    }

    /**
     * Tells the candidate of an application (in the language they applied in).
     *
     * @param  array<string, string>|callable(string $locale): array<string, string>  $values  More placeholder values.
     */
    public function tellCandidate(string $key, Organization $company, Application $application, array|callable $values = []): void
    {
        $candidate = $this->place->query(Candidate::class, $company)->whereKey($application->candidate_id)->first();
        $opening = $this->place->query(Opening::class, $company)->whereKey($application->opening_id)->first();
        if ($candidate === null || $opening === null) {
            return;
        }
        $this->notifier->notifyContacts($key, [['name' => $candidate->name, 'email' => $candidate->email, 'locale' => $candidate->locale]],
            fn (string $language) => [
                'organization' => $company->displayName($language), 'name' => (string) $candidate->name, 'job' => $opening->textIn('title', $language),
                ...(is_callable($values) ? $values($language) : $values),
            ], $company->partner, $company, ['mail'], '/');
    }

    /** @param array<string, mixed> $data */
    private function candidate(Organization $company, ?Candidate $candidate, array $data, ?string $locale, bool $consent): Candidate
    {
        $candidate ??= new Candidate(['organization_id' => $company->getKey(), 'email_hash' => Candidate::hashOf($data['email'])]);
        $candidate->fill([
            'name' => trim($data['name']), 'email' => mb_strtolower(trim($data['email'])),
            'phone' => $this->clean($data['phone'] ?? null) ?? $candidate->phone, 'locale' => $locale ?? $candidate->locale,
            ...($consent ? ['consent_at' => now()] : []),
        ])->save();

        return $candidate;
    }

    private function move(Organization $company, Application $application, ?string $from, ?string $to, ?string $note, ?User $actor, ?string $status = null): void
    {
        (new StageMove)->fill([
            'organization_id' => $company->getKey(), 'application_id' => $application->getKey(), 'from_stage' => $from, 'to_stage' => $to,
            'status' => $status ?? $application->status, 'note' => $note === null ? null : Str::limit($note, 490, ''), 'user_id' => $actor?->getKey(),
        ])->save();
    }

    private function cancelInterviews(Organization $company, Application $application): void
    {
        $this->place->query(Interview::class, $company)->where('application_id', $application->getKey())->where('status', Interview::SCHEDULED)
            ->get()->each(fn (Interview $interview) => $interview->forceFill(['status' => Interview::CANCELLED, 'version' => $interview->version + 1])->save());
    }

    private function active(Organization $company, Application $application, int $baseVersion): Application
    {
        $application = $this->fresh($company, $application, $baseVersion);
        if ($application->status !== Application::ACTIVE) {
            throw RecruitmentException::finished();
        }

        return $application;
    }

    private function fresh(Organization $company, Application $application, int $baseVersion): Application
    {
        /** @var Application $fresh */
        $fresh = $this->place->query(Application::class, $company)->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw RecruitmentException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Application $application): array
    {
        return $application->only(['opening_id', 'candidate_id', 'stage', 'status', 'source', 'rating']);
    }
}
