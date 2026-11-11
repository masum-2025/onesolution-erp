<?php

namespace Modules\Inpatient\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Inpatient\Events\Admitted;
use Modules\Inpatient\Events\BedMoved;
use Modules\Inpatient\Exceptions\InpatientException;
use Modules\Inpatient\Models\Admission;
use Modules\Inpatient\Models\Bed;
use Modules\Inpatient\Models\BedCharge;
use Modules\Inpatient\Models\BedMove;
use Modules\Inpatient\Models\Ward;
use Modules\PatientBilling\Contracts\PatientCharges;
use Modules\Patients\Contracts\PatientDirectory;

/**
 * A patient's stay:
 *
 * - Admitting takes a free bed under a lock (two desks never put two patients
 *   in one bed), gives the next number, needs the advance the rule
 *   inpatient.min_advance asks for (on the patient's advance in billing), links
 *   the attending doctor to the patient, and charges the first bed day.
 * - A bed move charges the days so far first, frees the old bed for cleaning
 *   and keeps the move (append-only).
 * - A mistaken admission is cancelled with a reason while its bill is open
 *   (its bed days come off the bill); the bed goes to cleaning.
 * With patients.doctor_sees = own a doctor who only does rounds sees only
 * their own patients. Audited without the patient's details.
 */
class Admissions
{
    public function __construct(
        private InpatientStore $store,
        private PatientDirectory $patients,
        private PatientCharges $charges,
        private BedCharges $bedCharges,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{patient_id: string, bed_id: string, doctor_user_id: string, reason?: ?string, guardian_name?: ?string, guardian_mobile?: ?string, op_id?: ?string}  $data
     */
    public function admit(Organization $company, Organization $unit, array $data, User $actor): Admission
    {
        $opId = $data['op_id'] ?? null;
        if ($opId !== null && ($done = $this->store->query(Admission::class, $company)->where('op_id', $opId)->first()) !== null) {
            return $done;
        }
        $patient = $this->patients->find($company, $data['patient_id']) ?? throw InpatientException::notFound('patient');
        if ($this->store->query(Admission::class, $company)->where('patient_id', $patient->id)->where('status', Admission::ADMITTED)->exists()) {
            throw InpatientException::because('already_admitted', 409);
        }
        if (! $this->isStaff($company, $data['doctor_user_id'])) {
            throw ValidationException::withMessages(['doctor_user_id' => __('inpatient::messages.validation.not_staff')]);
        }
        $bed = $this->bed($company, $unit, $data['bed_id']);
        $bedUnit = Organization::query()->find($bed->unit_id) ?? $unit;
        $needed = $this->store->minAdvance($company, $bedUnit);
        if ($needed !== null && ($has = $this->charges->creditOf($company, $patient->id)) < $needed) {
            throw InpatientException::because('advance_needed', 422, ['needed_minor' => $needed, 'has_minor' => $has]);
        }
        $now = CarbonImmutable::now();

        try {
            $admission = $this->store->transaction($company, function () use ($company, $bed, $patient, $data, $opId, $actor, $now) {
                $locked = $this->store->query(Bed::class, $company)->whereKey($bed->getKey())->lockForUpdate()->first();
                if ($locked->status !== Bed::FREE || ! $locked->is_active) {
                    throw InpatientException::because('bed_taken', 409, ['status' => $locked->status]);
                }
                $admission = new Admission([
                    'organization_id' => $company->getKey(), 'unit_id' => $locked->unit_id, 'number' => $this->store->nextNumber($company),
                    'patient_id' => $patient->id, 'doctor_user_id' => $data['doctor_user_id'], 'bed_id' => $locked->getKey(), 'admitted_at' => $now,
                    'reason' => $data['reason'] ?? null, 'guardian_name' => $data['guardian_name'] ?? null, 'guardian_mobile' => $data['guardian_mobile'] ?? null,
                    'admitted_by' => $actor->getKey(), 'op_id' => $opId,
                ]);
                $admission->save();
                (new BedMove(['organization_id' => $company->getKey(), 'admission_id' => $admission->getKey(), 'from_bed_id' => null, 'to_bed_id' => $locked->getKey(), 'moved_at' => $now, 'moved_by' => $actor->getKey()]))->save();
                $locked->forceFill(['status' => Bed::OCCUPIED, 'admission_id' => $admission->getKey(), 'version' => $locked->version + 1])->save();
                $this->patients->linkCarer($company, $patient->id, $data['doctor_user_id'], 'inpatient');
                $this->audit->record('inpatient.admitted', $admission, new: ['number' => $admission->number, 'bed' => $locked->code], actor: $actor, organizationId: $company->getKey());

                return $admission;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($opId !== null && ($done = $this->store->query(Admission::class, $company)->where('op_id', $opId)->first()) !== null) {
                return $done;
            }
            throw $e;
        }
        $this->bedCharges->chargeUpTo($company, $admission, $now, $actor);
        Admitted::dispatch((string) $company->getKey(), (string) $admission->getKey(), (string) $admission->patient_id, (string) $admission->bed_id);

        return $admission->refresh();
    }

    public function move(Organization $company, Organization $unit, Admission $admission, string $bedId, ?string $reason, int $baseVersion, User $actor): Admission
    {
        if ($admission->status !== Admission::ADMITTED) {
            throw InpatientException::because('not_admitted', 409);
        }
        $target = $this->bed($company, $unit, $bedId);
        $now = CarbonImmutable::now();
        // The days so far are charged at the bed they were spent in.
        $this->bedCharges->chargeUpTo($company, $admission, $now, $actor);

        $moved = $this->store->transaction($company, function () use ($company, $admission, $target, $reason, $baseVersion, $actor, $now) {
            $admission = $this->store->query(Admission::class, $company)->whereKey($admission->getKey())->lockForUpdate()->firstOrFail();
            if ($admission->version !== $baseVersion) {
                throw InpatientException::versionConflict(['version' => $admission->version]);
            }
            if ((string) $admission->bed_id === (string) $target->getKey()) {
                throw InpatientException::because('same_bed');
            }
            $beds = $this->store->query(Bed::class, $company)->whereKey([$admission->bed_id, $target->getKey()])->lockForUpdate()->get()->keyBy(fn ($bed) => (string) $bed->getKey());
            $to = $beds[(string) $target->getKey()];
            if ($to->status !== Bed::FREE || ! $to->is_active) {
                throw InpatientException::because('bed_taken', 409, ['status' => $to->status]);
            }
            if (($from = $beds->get((string) $admission->bed_id)) !== null) {
                $from->forceFill(['status' => Bed::CLEANING, 'admission_id' => null, 'version' => $from->version + 1])->save();
            }
            $to->forceFill(['status' => Bed::OCCUPIED, 'admission_id' => $admission->getKey(), 'version' => $to->version + 1])->save();
            (new BedMove(['organization_id' => $company->getKey(), 'admission_id' => $admission->getKey(), 'from_bed_id' => $admission->bed_id, 'to_bed_id' => $to->getKey(), 'moved_at' => $now, 'reason' => $reason, 'moved_by' => $actor->getKey()]))->save();
            $admission->forceFill(['bed_id' => $to->getKey(), 'version' => $admission->version + 1])->save();
            $this->audit->record('inpatient.bed_moved', $admission, old: ['bed' => $from?->code], new: ['number' => $admission->number, 'bed' => $to->code], actor: $actor, organizationId: $company->getKey());

            return $admission;
        });
        BedMoved::dispatch((string) $company->getKey(), (string) $moved->getKey(), (string) $moved->patient_id, (string) $moved->bed_id);

        return $moved;
    }

    public function cancel(Organization $company, Admission $admission, string $reason, int $baseVersion, User $actor): Admission
    {
        return $this->store->transaction($company, function () use ($company, $admission, $reason, $baseVersion, $actor) {
            $admission = $this->store->query(Admission::class, $company)->whereKey($admission->getKey())->lockForUpdate()->firstOrFail();
            if ($admission->version !== $baseVersion) {
                throw InpatientException::versionConflict(['version' => $admission->version]);
            }
            if ($admission->status !== Admission::ADMITTED) {
                throw InpatientException::because('not_admitted', 409);
            }
            foreach ($this->store->query(BedCharge::class, $company)->where('admission_id', $admission->getKey())->get() as $charge) {
                if (! $this->charges->withdraw($company, 'inpatient', 'bed_day', (string) $charge->getKey(), $actor)) {
                    throw InpatientException::because('bill_final', 409);
                }
            }
            $bed = $this->store->query(Bed::class, $company)->whereKey($admission->bed_id)->lockForUpdate()->first();
            $bed?->forceFill(['status' => Bed::CLEANING, 'admission_id' => null, 'version' => $bed->version + 1])->save();
            $admission->forceFill(['status' => Admission::CANCELLED, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => $reason, 'version' => $admission->version + 1])->save();
            $this->audit->record('inpatient.admission_cancelled', $admission, new: ['number' => $admission->number], actor: $actor, organizationId: $company->getKey());

            return $admission;
        });
    }

    /** Whether the person is a doctor limited to their own patients here (patients.doctor_sees = own). */
    public function onlyOwn(Organization $unit, User $user): bool
    {
        return $this->store->ruleOf('patients.doctor_sees', $unit) === 'own'
            && Gate::forUser($user)->allows('inpatient.round', $unit)
            && ! Gate::forUser($user)->any(['inpatient.admit', 'inpatient.manage'], $unit);
    }

    /** A stay at the unit and below the person may see; anything else is the same 404. */
    public function visible(Organization $company, Organization $unit, User $user, string $id): Admission
    {
        return $this->store->query(Admission::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->when($this->onlyOwn($unit, $user), fn ($query) => $query->where('doctor_user_id', $user->getKey()))
            ->first() ?? throw InpatientException::notFound('admission');
    }

    /** @return Collection<int, User> Staff of the company who may attend (owners and staff, not portal members). */
    public function staff(Organization $company): Collection
    {
        $ids = OrganizationMembership::query()->whereIn('organization_id', $this->store->subtreeIds($company))
            ->where('status', MembershipStatus::Active)->where('membership_type', '!=', MembershipType::Portal->value)->pluck('user_id')->unique();

        return User::query()->whereKey($ids->all())->orderBy('name')->get(['id', 'name']);
    }

    private function isStaff(Organization $company, string $userId): bool
    {
        return OrganizationMembership::query()->where('user_id', $userId)->whereIn('organization_id', $this->store->subtreeIds($company))
            ->where('status', MembershipStatus::Active)->where('membership_type', '!=', MembershipType::Portal->value)->exists();
    }

    /** An active bed of an active ward at the unit and below. */
    private function bed(Organization $company, Organization $unit, string $id): Bed
    {
        $bed = $this->store->query(Bed::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->where('is_active', true)->first()
            ?? throw InpatientException::notFound('bed');
        if (! $this->store->query(Ward::class, $company)->whereKey($bed->ward_id)->where('is_active', true)->exists()) {
            throw InpatientException::notFound('bed');
        }

        return $bed;
    }
}
