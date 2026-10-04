<?php

namespace Modules\Attendance\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Attendance\Exceptions\AttendanceException;
use Modules\Attendance\Models\Correction;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;

/**
 * Asking to fix a day: the in and/or out time it should have had, with a
 * reason, at most the rule's days back (attendance.correction_max_days).
 * Someone else with attendance.correct approves (the times become punches
 * and the day is worked out again) or rejects it; never the person who
 * asked. Every step is audited.
 */
class Corrections
{
    public function __construct(
        private Workplace $workplace,
        private Punches $punches,
        private EmployeeDirectory $directory,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{work_date: string, in_at?: string|null, out_at?: string|null, reason: string}  $data  Times are local ("2026-10-14T09:05").
     */
    public function ask(Organization $company, EmployeeRecord $employee, array $data, User $actor): Correction
    {
        $day = CarbonImmutable::parse($data['work_date'], 'UTC');
        $today = $this->workplace->today($company);
        $back = (int) $this->rules->get('attendance.correction_max_days', $this->contexts->forOrganization(Organization::query()->findOrFail($employee->unitId)));
        if ($day->greaterThan($today) || $day->lessThan($today->subDays($back))) {
            throw ValidationException::withMessages(['work_date' => __('attendance::attendance.validation.correction_window', ['days' => $back])]);
        }
        if (! $employee->employedOn($day)) {
            throw AttendanceException::notEmployed();
        }
        $in = empty($data['in_at']) ? null : $this->workplace->localInstant($company, $data['in_at']);
        $out = empty($data['out_at']) ? null : $this->workplace->localInstant($company, $data['out_at']);
        if ($in !== null && $out !== null && ! $out->greaterThan($in)) {
            throw ValidationException::withMessages(['out_at' => __('attendance::attendance.validation.out_before_in')]);
        }
        // The times belong around the day asked about (a night shift may end the next morning).
        foreach (['in_at' => $in, 'out_at' => $out] as $field => $instant) {
            if ($instant !== null && abs($this->workplace->dayOf($company, $instant)->diffInDays($day, false)) > 1) {
                throw ValidationException::withMessages([$field => __('attendance::attendance.validation.time_off_day')]);
            }
        }

        return $this->workplace->transaction($company, function () use ($company, $employee, $day, $in, $out, $data, $actor) {
            $correction = new Correction;
            $correction->fill([
                'organization_id' => $company->getKey(), 'unit_id' => $employee->unitId, 'employee_id' => $employee->id,
                'work_date' => $day->toDateString(), 'in_at' => $in, 'out_at' => $out, 'reason' => $data['reason'],
                'status' => Correction::PENDING, 'requested_by' => $actor->getKey(), 'version' => 1,
            ])->save();
            $this->audit->record('attendance.correction_asked', $correction, new: $this->values($correction), reason: $data['reason'], actor: $actor, organizationId: $company->getKey());

            return $correction;
        });
    }

    public function approve(Organization $company, Correction $correction, int $baseVersion, User $actor): Correction
    {
        return $this->workplace->transaction($company, function () use ($company, $correction, $baseVersion, $actor) {
            $correction = $this->pending($company, $correction, $baseVersion, $actor);
            $employee = $this->directory->find($company, $correction->employee_id) ?? throw AttendanceException::employeeNotFound();
            foreach (['in' => $correction->in_at, 'out' => $correction->out_at] as $side => $instant) {
                if ($instant !== null) {
                    $this->punches->fromCorrection($company, $employee, $instant, "correction-{$correction->getKey()}-{$side}", $actor);
                }
            }

            return $this->decide($company, $correction, Correction::APPROVED, null, $actor);
        });
    }

    public function reject(Organization $company, Correction $correction, int $baseVersion, ?string $note, User $actor): Correction
    {
        return $this->workplace->transaction($company, function () use ($company, $correction, $baseVersion, $note, $actor) {
            return $this->decide($company, $this->pending($company, $correction, $baseVersion, $actor), Correction::REJECTED, $note, $actor);
        });
    }

    private function decide(Organization $company, Correction $correction, string $status, ?string $note, User $actor): Correction
    {
        $correction->forceFill(['status' => $status, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_note' => $note, 'version' => $correction->version + 1])->save();
        $this->audit->record("attendance.correction_{$status}", $correction, new: $this->values($correction), reason: $note, actor: $actor, organizationId: $company->getKey());

        return $correction;
    }

    private function pending(Organization $company, Correction $correction, int $baseVersion, User $actor): Correction
    {
        /** @var Correction $fresh */
        $fresh = $this->workplace->query(Correction::class, $company)->whereKey($correction->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw AttendanceException::versionConflict(['version' => $fresh->version, 'status' => $fresh->status]);
        }
        if ($fresh->status !== Correction::PENDING) {
            throw AttendanceException::correctionDecided();
        }
        // Asked by them, or about their own day: someone else decides.
        $own = $this->directory->find($company, $fresh->employee_id)?->userId === $actor->getKey();
        if ($fresh->requested_by === $actor->getKey() || $own) {
            throw AttendanceException::ownCorrection();
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Correction $correction): array
    {
        return [
            'employee_id' => $correction->employee_id, 'work_date' => $correction->work_date->toDateString(),
            'in_at' => $correction->in_at?->toIso8601String(), 'out_at' => $correction->out_at?->toIso8601String(), 'status' => $correction->status,
        ];
    }
}
