<?php

namespace Modules\Recruitment\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Recruitment\Exceptions\RecruitmentException;
use Modules\Recruitment\Models\Application;
use Modules\Recruitment\Models\Feedback;
use Modules\Recruitment\Models\Interview;
use Modules\Recruitment\Models\Opening;

/**
 * Interviews of an application: when (the company's local time, kept in
 * UTC), how long, in person, by phone or video, where, and who interviews
 * (people who may interview or manage recruitment where the job is). The
 * interviewers are told, and the candidate when HR wishes. Each
 * interviewer gives their score and recommendation; when all have, the
 * interview is done.
 */
class Interviews
{
    public function __construct(
        private Place $place,
        private Applications $applications,
        private OrganizationSettingsResolver $settings,
        private Recipients $recipients,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{starts_at: string, minutes: int, mode: string, place?: string|null, interviewer_ids: list<string>, tell_candidate?: bool}  $data
     */
    public function schedule(Organization $company, Application $application, array $data, User $actor): Interview
    {
        if ($application->status !== Application::ACTIVE) {
            throw RecruitmentException::finished();
        }
        $unit = Organization::query()->findOrFail($application->unit_id);
        $allowed = $this->interviewers($unit)->map(fn (User $user) => $user->getKey())->all();
        $ids = array_values(array_unique($data['interviewer_ids']));
        if (array_diff($ids, $allowed) !== []) {
            throw ValidationException::withMessages(['interviewer_ids' => __('recruitment::recruitment.validation.interviewers')]);
        }
        $startsAt = CarbonImmutable::parse($data['starts_at'], $this->zone($company))->utc();
        if ($startsAt->isPast()) {
            throw ValidationException::withMessages(['starts_at' => __('recruitment::recruitment.validation.past')]);
        }

        $interview = $this->place->transaction($company, function () use ($company, $application, $data, $ids, $startsAt, $actor) {
            $interview = new Interview;
            $interview->fill([
                'organization_id' => $company->getKey(), 'application_id' => $application->getKey(), 'starts_at' => $startsAt,
                'minutes' => $data['minutes'], 'mode' => $data['mode'], 'place' => trim((string) ($data['place'] ?? '')) ?: null,
                'interviewer_ids' => $ids, 'status' => Interview::SCHEDULED, 'created_by' => $actor->getKey(), 'version' => 1,
            ])->save();
            $this->audit->record('recruitment.interview_scheduled', $interview, new: ['application_id' => $application->getKey(), 'starts_at' => $startsAt->toIso8601String(), 'mode' => $data['mode'], 'interviewers' => count($ids)], actor: $actor, organizationId: $company->getKey());

            return $interview;
        });

        $opening = $this->place->query(Opening::class, $company)->whereKey($application->opening_id)->first();
        $values = fn (string $language) => [
            'organization' => $company->displayName($language), 'job' => (string) $opening?->textIn('title', $language),
            'when' => $this->when($company, $interview, $language), 'mode' => __("recruitment::recruitment.modes.{$interview->mode}", [], $language),
            'place' => (string) $interview->place,
        ];
        $this->notifier->notify('recruitment.interview_scheduled', User::query()->whereKey($ids)->get(), $values, $company->partner, $company);
        if ((bool) ($data['tell_candidate'] ?? true)) {
            $this->applications->tellCandidate('recruitment.interview_invite', $company, $application, $values);
        }

        return $interview;
    }

    public function cancel(Organization $company, Interview $interview, int $baseVersion, User $actor): Interview
    {
        return $this->place->transaction($company, function () use ($company, $interview, $baseVersion, $actor) {
            $interview = $this->fresh($company, $interview, $baseVersion);
            if ($interview->status !== Interview::SCHEDULED) {
                throw RecruitmentException::finished();
            }
            $interview->forceFill(['status' => Interview::CANCELLED, 'version' => $interview->version + 1])->save();
            $this->audit->record('recruitment.interview_cancelled', $interview, new: ['application_id' => $interview->application_id], actor: $actor, organizationId: $company->getKey());

            return $interview;
        });
    }

    /**
     * An interviewer's own feedback (given again, it replaces theirs). When every interviewer has given it, the interview is done.
     *
     * @param  array{score: int, recommendation: string, note?: string|null}  $data
     */
    public function feedback(Organization $company, Interview $interview, array $data, User $actor): Feedback
    {
        return $this->place->transaction($company, function () use ($company, $interview, $data, $actor) {
            /** @var Interview $interview */
            $interview = $this->place->query(Interview::class, $company)->whereKey($interview->getKey())->lockForUpdate()->firstOrFail();
            if ($interview->status === Interview::CANCELLED || ! in_array($actor->getKey(), $interview->interviewer_ids, true)) {
                throw RecruitmentException::cannotFeedback();
            }
            $feedback = $this->place->query(Feedback::class, $company)->where('interview_id', $interview->getKey())->where('user_id', $actor->getKey())->first()
                ?? new Feedback(['organization_id' => $company->getKey(), 'interview_id' => $interview->getKey(), 'application_id' => $interview->application_id, 'user_id' => $actor->getKey()]);
            $feedback->fill(['score' => $data['score'], 'recommendation' => $data['recommendation'], 'note' => trim((string) ($data['note'] ?? '')) ?: null])->save();
            $given = $this->place->query(Feedback::class, $company)->where('interview_id', $interview->getKey())->pluck('user_id')->all();
            if (array_diff($interview->interviewer_ids, $given) === [] && $interview->status === Interview::SCHEDULED) {
                $interview->forceFill(['status' => Interview::DONE, 'version' => $interview->version + 1])->save();
            }
            $this->audit->record('recruitment.feedback_given', $interview, new: ['score' => $feedback->score, 'recommendation' => $feedback->recommendation], actor: $actor, organizationId: $company->getKey());

            return $feedback;
        });
    }

    /**
     * Whom one may ask to interview where the job is: people who may interview or manage recruitment there.
     *
     * @return Collection<int, User>
     */
    public function interviewers(Organization $unit): Collection
    {
        return $this->recipients->holding('recruitment.interview', $unit)->merge($this->recipients->holding('recruitment.manage', $unit))
            ->unique(fn (User $user) => $user->getKey())->values();
    }

    /** Whether this person is asked to interview for the application (any interview not cancelled). */
    public function isInterviewer(Organization $company, Application $application, User $user): bool
    {
        return $this->place->query(Interview::class, $company)->where('application_id', $application->getKey())
            ->where('status', '!=', Interview::CANCELLED)->whereJsonContains('interviewer_ids', $user->getKey())->exists();
    }

    /** "28 Oct 2026, 10:30" in the company's time and the reader's language. */
    public function when(Organization $company, Interview $interview, string $locale): string
    {
        return CarbonImmutable::parse($interview->starts_at)->setTimezone($this->zone($company))->locale($locale)->isoFormat('LLL');
    }

    public function zone(Organization $company): string
    {
        return ($this->settings->values($company)['timezone'] ?? null) ?: 'UTC';
    }

    private function fresh(Organization $company, Interview $interview, int $baseVersion): Interview
    {
        /** @var Interview $fresh */
        $fresh = $this->place->query(Interview::class, $company)->whereKey($interview->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw RecruitmentException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }
}
