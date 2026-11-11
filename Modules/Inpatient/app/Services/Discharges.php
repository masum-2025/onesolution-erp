<?php

namespace Modules\Inpatient\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Inpatient\Events\Discharged;
use Modules\Inpatient\Exceptions\InpatientException;
use Modules\Inpatient\Models\Admission;
use Modules\Inpatient\Models\Bed;
use Modules\Inpatient\Models\BedCharge;
use Modules\Inpatient\Models\Discharge;
use Modules\Inpatient\Models\Ward;
use Modules\PatientBilling\Contracts\PatientCharges;
use Modules\Patients\Contracts\PatientDirectory;

/**
 * Sending a patient home.
 *
 * - The summary (diagnosis, treatment, advice, follow-up, outcome) is a draft
 *   with its version while the patient is in.
 * - Before discharge the bed days are counted as at discharge (rule
 *   inpatient.bed_day_starts: at midnight the discharge day does not count), so
 *   the final due is known first. With inpatient.discharge_requires_clearance
 *   nothing may be due then; the refusal says how much is.
 * - Discharging charges the missing bed days, takes back the extra ones while
 *   the bill is open (marked, never removed), signs the summary, sends the bed
 *   to cleaning and puts a line on the patient's history (no diagnosis).
 * - A signed summary is never changed: an amendment, with a reason, is the
 *   next signed revision and the earlier one is kept, marked superseded.
 * Audited with numbers only, never what was written.
 */
class Discharges
{
    public function __construct(
        private InpatientStore $store,
        private BedCharges $bedCharges,
        private PatientCharges $charges,
        private PatientDirectory $patients,
        private AuditLogger $audit,
    ) {}

    /** The latest revision (draft or signed). */
    public function current(Organization $company, Admission $admission): ?Discharge
    {
        return $this->store->query(Discharge::class, $company)->where('admission_id', $admission->getKey())->orderByDesc('revision')->first();
    }

    /** The signed revision in force (the one printed and shown in the portal). */
    public function inForce(Organization $company, Admission $admission): ?Discharge
    {
        return $this->store->query(Discharge::class, $company)->where('admission_id', $admission->getKey())->where('status', Discharge::SIGNED)
            ->whereNull('superseded_at')->orderByDesc('revision')->first();
    }

    /**
     * What the bill comes to if the patient leaves now.
     *
     * @return array{bed_days: int, due_minor: int, clearance_required: bool, advance_minor: int}
     */
    public function preview(Organization $company, Admission $admission, ?CarbonImmutable $at = null): array
    {
        $unit = Organization::query()->find($admission->unit_id) ?? $company;
        $final = $this->finalDays($company, $unit, $admission, $at ?? CarbonImmutable::now());
        $charged = $this->store->query(BedCharge::class, $company)->where('admission_id', $admission->getKey())->whereNull('withdrawn_at')->get();
        $due = $admission->bill_id === null ? 0 : (int) ($this->charges->dueOn($company, $admission->bill_id) ?? 0);
        $lines = $admission->bill_id === null ? [] : ($this->charges->chargesOn($company, $admission->bill_id, 'inpatient')['lines'] ?? []);
        foreach ($charged->where('day_no', '>', $final) as $extra) {
            $due -= (int) ($lines[(string) $extra->getKey()] ?? 0);
        }
        $missing = max(0, $final - (int) $this->store->query(BedCharge::class, $company)->where('admission_id', $admission->getKey())->max('day_no'));
        if ($missing > 0) {
            $code = $this->store->query(Bed::class, $company)->whereKey($admission->bed_id)->value('service_code');
            $due += $missing * (int) ($this->charges->services($company)[$code]['price_minor'] ?? 0);
        }

        return [
            'bed_days' => $final, 'due_minor' => max(0, $due),
            'clearance_required' => (bool) $this->store->rule('discharge_requires_clearance', $unit),
            'advance_minor' => $this->charges->creditOf($company, $admission->patient_id),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  diagnosis, treatment, advice, follow_up_on, outcome
     */
    public function save(Organization $company, Admission $admission, array $data, ?int $baseVersion, User $actor): Discharge
    {
        if ($admission->status !== Admission::ADMITTED) {
            throw InpatientException::because('not_admitted', 409);
        }

        return $this->store->transaction($company, function () use ($company, $admission, $data, $baseVersion, $actor) {
            $current = $this->store->query(Discharge::class, $company)->where('admission_id', $admission->getKey())->orderByDesc('revision')->lockForUpdate()->first();
            if ($current === null) {
                $current = new Discharge(['organization_id' => $company->getKey(), 'admission_id' => $admission->getKey(), 'written_by' => $actor->getKey()]);
            } elseif ($current->status === Discharge::SIGNED) {
                throw InpatientException::because('already_signed', 409);
            } elseif ($current->version !== $baseVersion) {
                throw InpatientException::versionConflict(['version' => $current->version]);
            } else {
                $current->version++;
            }
            $current->fill($this->texts($data));
            $current->save();

            return $current;
        });
    }

    public function discharge(Organization $company, Admission $admission, int $baseVersion, User $actor): Admission
    {
        if ($admission->status !== Admission::ADMITTED) {
            throw InpatientException::because('not_admitted', 409);
        }
        if ($admission->version !== $baseVersion) {
            throw InpatientException::versionConflict(['version' => $admission->version]);
        }
        $summary = $this->current($company, $admission);
        if ($summary === null || $summary->status !== Discharge::DRAFT || trim((string) $summary->diagnosis) === '') {
            throw InpatientException::because('summary_needed');
        }
        $now = CarbonImmutable::now();
        $preview = $this->preview($company, $admission, $now);
        if ($preview['clearance_required'] && $preview['due_minor'] > 0) {
            throw InpatientException::because('dues_left', 422, ['due_minor' => $preview['due_minor'], 'advance_minor' => $preview['advance_minor']]);
        }

        $done = $this->store->transaction($company, function () use ($company, $admission, $baseVersion, $actor, $now, $preview) {
            $locked = $this->store->query(Admission::class, $company)->whereKey($admission->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== Admission::ADMITTED) {
                throw InpatientException::because('not_admitted', 409);
            }
            if ($locked->version !== $baseVersion) {
                throw InpatientException::versionConflict(['version' => $locked->version]);
            }
            $locked->discharged_at = $now;
            $this->bedCharges->chargeUpTo($company, $locked, $now, $actor);
            $extra = $this->store->query(BedCharge::class, $company)->where('admission_id', $locked->getKey())->whereNull('withdrawn_at')->where('day_no', '>', $preview['bed_days'])->get();
            foreach ($extra as $charge) {
                if ($this->charges->withdraw($company, 'inpatient', 'bed_day', (string) $charge->getKey(), $actor)) {
                    $charge->forceFill(['withdrawn_at' => $now])->save();
                }
            }
            $locked->forceFill(['status' => Admission::DISCHARGED, 'discharged_at' => $now, 'version' => $locked->version + 1])->save();
            $summary = $this->store->query(Discharge::class, $company)->where('admission_id', $locked->getKey())->orderByDesc('revision')->lockForUpdate()->first();
            $summary->forceFill(['status' => Discharge::SIGNED, 'signed_by' => $actor->getKey(), 'signed_at' => $now, 'version' => $summary->version + 1])->save();
            $bed = $this->store->query(Bed::class, $company)->whereKey($locked->bed_id)->lockForUpdate()->first();
            $bed?->forceFill(['status' => Bed::CLEANING, 'admission_id' => null, 'version' => $bed->version + 1])->save();
            $ward = $bed === null ? null : $this->store->query(Ward::class, $company)->find($bed->ward_id);
            $this->patients->addToTimeline($company, $locked->patient_id, 'inpatient', 'discharge', (string) $locked->getKey(), [
                'en' => __('inpatient::messages.timeline.discharged', ['number' => $locked->number, 'ward' => $ward?->textIn('name', 'en') ?? ''], 'en'),
                'bn' => __('inpatient::messages.timeline.discharged', ['number' => $locked->number, 'ward' => $ward?->textIn('name', 'bn') ?? ''], 'bn'),
            ], $now);
            $this->audit->record('inpatient.discharged', $locked, new: ['number' => $locked->number, 'bed_days' => $preview['bed_days']], actor: $actor, organizationId: $company->getKey());

            return [$locked, $summary];
        });
        [$discharged, $summary] = $done;
        Discharged::dispatch((string) $company->getKey(), (string) $discharged->getKey(), (string) $discharged->patient_id, (string) $summary->getKey());

        return $discharged;
    }

    /**
     * @param  array<string, mixed>  $data  The whole corrected summary.
     */
    public function amend(Organization $company, Admission $admission, array $data, string $reason, User $actor): Discharge
    {
        if ($admission->status !== Admission::DISCHARGED) {
            throw InpatientException::because('not_discharged', 409);
        }

        return $this->store->transaction($company, function () use ($company, $admission, $data, $reason, $actor) {
            $current = $this->store->query(Discharge::class, $company)->where('admission_id', $admission->getKey())->orderByDesc('revision')->lockForUpdate()->first();
            if ($current === null || $current->status !== Discharge::SIGNED) {
                throw InpatientException::because('not_discharged', 409);
            }
            $now = CarbonImmutable::now();
            $next = new Discharge([
                ...$this->texts($data), 'organization_id' => $company->getKey(), 'admission_id' => $admission->getKey(),
                'revision' => $current->revision + 1, 'amend_reason' => $reason, 'written_by' => $actor->getKey(),
            ]);
            $next->forceFill(['status' => Discharge::SIGNED, 'signed_by' => $actor->getKey(), 'signed_at' => $now])->save();
            $current->forceFill(['superseded_at' => $now])->save();
            $this->audit->record('inpatient.discharge_amended', $next, new: ['number' => $admission->number, 'revision' => $next->revision], actor: $actor, organizationId: $company->getKey());

            return $next;
        });
    }

    /** The bed days the stay comes to if it ends at a moment. */
    private function finalDays(Organization $company, Organization $unit, Admission $admission, CarbonImmutable $at): int
    {
        return BedDays::count((string) $this->store->rule('bed_day_starts', $unit), CarbonImmutable::parse($admission->admitted_at), $at, true, $this->store->timezone($company));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function texts(array $data): array
    {
        $texts = [];
        foreach (Discharge::TEXTS as $field) {
            $value = $data[$field] ?? null;
            $texts[$field] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }

        return $texts;
    }
}
