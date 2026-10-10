<?php

namespace Modules\EducationFees\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Modules\EducationFees\Exceptions\FeeException;
use Modules\EducationFees\Models\Concession;

/**
 * A student's discounts and scholarships. A percent at or below the rule
 * education_fees.concession_approval_above_percent is active at once;
 * a higher percent or any fixed amount waits for another person (never the
 * one who asked). A concession is never changed: it ends, and a new one is
 * given. Bills made while it is active carry it.
 */
class Concessions
{
    public function __construct(private FeeOffice $office, private RuleResolver $rules, private RuleContextFactory $contexts, private AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data  student_id, unit_id, head_id?, mode, percent_bp|amount_minor, reason, starts_on, ends_on? */
    public function give(Organization $company, array $data, User $actor): Concession
    {
        $pending = $this->needsApproval($company, $data['mode'], (int) ($data['percent_bp'] ?? 0));

        return $this->office->transaction($company, function () use ($company, $data, $actor, $pending) {
            $concession = new Concession;
            $concession->fill([
                'organization_id' => $company->getKey(), 'unit_id' => $data['unit_id'], 'student_id' => $data['student_id'], 'head_id' => $data['head_id'] ?? null,
                'mode' => $data['mode'], 'percent_bp' => $data['mode'] === 'percent' ? (int) $data['percent_bp'] : null,
                'amount_minor' => $data['mode'] === 'fixed' ? (int) $data['amount_minor'] : null, 'reason' => $data['reason'],
                'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? null, 'status' => $pending ? 'pending' : 'active',
                'requested_by' => $actor->getKey(), 'version' => 1,
            ]);
            $concession->save();
            $this->audit->record('education_fees.concession_given', $concession, new: $this->audited($concession), reason: $concession->reason, actor: $actor, organizationId: $company->getKey());

            return $concession;
        });
    }

    /** Another person approves (active) or rejects a pending concession. */
    public function decide(Organization $company, Concession $concession, bool $approve, ?string $note, int $baseVersion, User $actor): Concession
    {
        return $this->office->transaction($company, function () use ($company, $concession, $approve, $note, $baseVersion, $actor) {
            $concession = $this->locked($company, $concession, $baseVersion);
            if ($concession->status !== 'pending') {
                throw FeeException::wrongStatus($concession->status);
            }
            if ($concession->requested_by === $actor->getKey()) {
                throw FeeException::ownApproval();
            }
            $concession->forceFill([
                'status' => $approve ? 'active' : 'rejected', 'decided_by' => $actor->getKey(), 'decided_at' => now(),
                'decision_note' => $note, 'version' => $concession->version + 1,
            ])->save();
            $this->audit->record('education_fees.concession_'.($approve ? 'approved' : 'rejected'), $concession, old: ['status' => 'pending'], new: ['status' => $concession->status], reason: $note, actor: $actor, organizationId: $company->getKey());

            return $concession;
        });
    }

    /** An active concession stops (the day before it ends is its last), a pending one is withdrawn. */
    public function end(Organization $company, Concession $concession, string $endsOn, ?string $note, int $baseVersion, User $actor): Concession
    {
        return $this->office->transaction($company, function () use ($company, $concession, $endsOn, $note, $baseVersion, $actor) {
            $concession = $this->locked($company, $concession, $baseVersion);
            if (! in_array($concession->status, ['pending', 'active'], true)) {
                throw FeeException::wrongStatus($concession->status);
            }
            $old = $concession->only(['status', 'ends_on']);
            $concession->forceFill([
                'status' => 'ended', 'ends_on' => max($endsOn, $concession->starts_on->toDateString()), 'decision_note' => $note ?? $concession->decision_note,
                'version' => $concession->version + 1,
            ])->save();
            $this->audit->record('education_fees.concession_ended', $concession, old: ['status' => $old['status'], 'ends_on' => $old['ends_on']?->toDateString()],
                new: ['status' => 'ended', 'ends_on' => $concession->ends_on->toDateString()], reason: $note, actor: $actor, organizationId: $company->getKey());

            return $concession;
        });
    }

    /**
     * Concessions that apply to bills issued on a day, by student: active
     * ones, and ended ones that still ran that day.
     *
     * @param  list<string>  $studentIds
     * @return array<string, Collection<int, Concession>>
     */
    public function on(Organization $company, array $studentIds, string $day): array
    {
        return $this->office->query(Concession::class, $company)->whereIn('student_id', $studentIds)->whereIn('status', ['active', 'ended'])
            ->where('starts_on', '<=', $day)->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $day))
            ->get()->groupBy('student_id')->all();
    }

    private function needsApproval(Organization $company, string $mode, int $percentBp): bool
    {
        $above = $this->rules->get('education_fees.concession_approval_above_percent', $this->contexts->forOrganization($company));
        if ($above === null) {
            return false;
        }

        return $mode === 'fixed' || $percentBp > 100 * (int) $above;
    }

    private function locked(Organization $company, Concession $concession, int $baseVersion): Concession
    {
        /** @var Concession $fresh */
        $fresh = $this->office->query(Concession::class, $company)->whereKey($concession->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw FeeException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }

    /** @return array<string, mixed> */
    private function audited(Concession $concession): array
    {
        return [...$concession->only(['student_id', 'head_id', 'mode', 'percent_bp', 'amount_minor', 'status']), 'starts_on' => $concession->starts_on->toDateString(), 'ends_on' => $concession->ends_on?->toDateString()];
    }
}
