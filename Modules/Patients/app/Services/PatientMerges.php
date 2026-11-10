<?php

namespace Modules\Patients\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Patients\Events\PatientsMerged;
use Modules\Patients\Exceptions\PatientsException;
use Modules\Patients\Models\CareLink;
use Modules\Patients\Models\MergeRequest;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\TimelineEntry;

/**
 * Folding a duplicate patient (source) into the record that stays (target),
 * maker-checker: one person asks, another approves or rejects. On approval
 * the source keeps its row (pointing to the target), its history and care
 * links move to the target, and other modules hear patients.merged to move
 * their own records. The "who looked" log is never moved or changed.
 */
class PatientMerges
{
    public function __construct(private PatientsStore $store, private AuditLogger $audit) {}

    public function request(Organization $company, Patient $source, Patient $target, string $reason, User $actor): MergeRequest
    {
        if ($source->is($target)) {
            throw PatientsException::mergeSelf();
        }
        foreach ([$source, $target] as $patient) {
            if ($patient->isMerged()) {
                throw PatientsException::merged($patient->merged_into_id);
            }
        }

        return $this->store->transaction($company, function () use ($company, $source, $target, $reason, $actor) {
            $waiting = $this->store->query(MergeRequest::class, $company)->where('status', MergeRequest::PENDING)
                ->where(fn ($query) => $query->whereIn('source_id', [$source->getKey(), $target->getKey()])->orWhereIn('target_id', [$source->getKey()]))
                ->lockForUpdate()->exists();
            if ($waiting) {
                throw PatientsException::mergeWaiting();
            }
            $merge = new MergeRequest([
                'organization_id' => $company->getKey(), 'source_id' => $source->getKey(), 'target_id' => $target->getKey(),
                'reason' => $reason, 'requested_by' => $actor->getKey(),
            ]);
            $merge->save();
            $this->audit->record('patients.merge_requested', $merge, new: ['source' => $source->mrn, 'target' => $target->mrn], reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $merge;
        });
    }

    public function decide(Organization $company, MergeRequest $merge, bool $approve, ?string $note, User $actor): MergeRequest
    {
        $merge = $this->store->transaction($company, function () use ($company, $merge, $approve, $note, $actor) {
            $merge = $this->store->query(MergeRequest::class, $company)->whereKey($merge->getKey())->lockForUpdate()->firstOrFail();
            if ($merge->status !== MergeRequest::PENDING) {
                throw PatientsException::notPending();
            }
            if ($merge->requested_by === $actor->getKey()) {
                throw PatientsException::ownRequest();
            }
            $patients = $this->store->query(Patient::class, $company)->whereKey([$merge->source_id, $merge->target_id])->lockForUpdate()->get()->keyBy('id');
            $source = $patients[$merge->source_id];
            $target = $patients[$merge->target_id];
            if ($approve && ($source->isMerged() || $target->isMerged())) {
                throw PatientsException::merged($source->merged_into_id ?? $target->merged_into_id);
            }
            $merge->fill([
                'status' => $approve ? MergeRequest::APPROVED : MergeRequest::REJECTED,
                'decided_by' => $actor->getKey(), 'decided_at' => CarbonImmutable::now(), 'decision_note' => $note,
            ])->save();

            if ($approve) {
                $source->merged_into_id = $target->getKey();
                $source->version++;
                $source->save();
                $this->store->query(TimelineEntry::class, $company)->where('patient_id', $source->getKey())->update(['patient_id' => $target->getKey()]);
                $known = $this->store->query(CareLink::class, $company)->where('patient_id', $target->getKey())->pluck('user_id')->all();
                $this->store->query(CareLink::class, $company)->where('patient_id', $source->getKey())->whereIn('user_id', $known)->delete();
                $this->store->query(CareLink::class, $company)->where('patient_id', $source->getKey())->update(['patient_id' => $target->getKey()]);
            }
            $this->audit->record($approve ? 'patients.merge_approved' : 'patients.merge_rejected', $merge,
                new: ['source' => $source->mrn, 'target' => $target->mrn], reason: $note, actor: $actor, organizationId: $company->getKey());

            return $merge;
        });
        if ($merge->status === MergeRequest::APPROVED) {
            PatientsMerged::dispatch($company->getKey(), $merge->source_id, $merge->target_id);
        }

        return $merge;
    }
}
