<?php

namespace Modules\Inpatient\Services;

use App\Models\User;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Inpatient\Models\Admission;
use Modules\Inpatient\Models\BedCharge;
use Modules\Inpatient\Models\BedMove;
use Modules\Inpatient\Models\Bed;
use Modules\PatientBilling\Contracts\PatientCharges;

/**
 * The daily bed charge: each bed day of a stay goes on the patient's bill
 * once (Patient billing's PatientCharges), at the price of the bed the
 * patient was in when the day began. Run at admission, before a bed move and
 * every hour for every company with Inpatient on (never with it off).
 */
class BedCharges
{
    public function __construct(
        private InpatientStore $store,
        private PatientCharges $charges,
        private ModuleResolver $modules,
        private TenantDatabases $databases,
    ) {}

    /** Every company's stays, up to now. Returns the bed days charged. */
    public function run(?CarbonImmutable $now = null): int
    {
        $charged = 0;
        foreach ([null, ...$this->databases->names()] as $database) {
            $this->databases->onConnection($this->databases->connectionFor($database), function () use ($now, &$charged) {
                $companies = Admission::query()->withoutGlobalScope(OrganizationScope::class)->where('status', Admission::ADMITTED)->distinct()->pluck('organization_id');
                foreach (Organization::query()->whereKey($companies->all())->get() as $company) {
                    $charged += $this->runFor($company, $now);
                }
            });
        }

        return $charged;
    }

    public function runFor(Organization $company, ?CarbonImmutable $now = null): int
    {
        if (! $this->modules->isEnabled('inpatient', $company)) {
            return 0;
        }
        $charged = 0;
        foreach ($this->store->query(Admission::class, $company)->where('status', Admission::ADMITTED)->get() as $admission) {
            $charged += $this->chargeUpTo($company, $admission, $now ?? CarbonImmutable::now());
        }

        return $charged;
    }

    /** Charges the bed days of a stay not charged yet, up to a moment. Returns how many. */
    public function chargeUpTo(Organization $company, Admission $admission, CarbonImmutable $at, ?User $actor = null): int
    {
        $unit = Organization::query()->find($admission->unit_id) ?? $company;
        $rule = (string) $this->store->rule('bed_day_starts', $unit);
        $timezone = $this->store->timezone($company);
        $until = $admission->discharged_at !== null ? CarbonImmutable::parse($admission->discharged_at) : $at;
        $due = BedDays::count($rule, CarbonImmutable::parse($admission->admitted_at), $until, $admission->discharged_at !== null, $timezone);
        $done = (int) $this->store->query(BedCharge::class, $company)->where('admission_id', $admission->getKey())->max('day_no');
        $actor ??= User::query()->find($admission->admitted_by);
        if ($due <= $done || $actor === null) {
            return 0;
        }
        $moves = $this->store->query(BedMove::class, $company)->where('admission_id', $admission->getKey())->orderBy('moved_at')->orderBy('id')->get();
        $charged = 0;
        for ($day = $done + 1; $day <= $due; $day++) {
            $start = BedDays::start($rule, CarbonImmutable::parse($admission->admitted_at), $day, $timezone);
            $bedId = (string) ($moves->filter(fn (BedMove $move) => CarbonImmutable::parse($move->moved_at)->lessThanOrEqualTo($start))->last()?->to_bed_id ?? $moves->first()?->to_bed_id ?? $admission->bed_id);
            $bed = $this->store->query(Bed::class, $company)->find($bedId);
            if ($bed === null) {
                continue;
            }
            try {
                $this->store->transaction($company, function () use ($company, $unit, $admission, $day, $start, $bed, $actor) {
                    $charge = new BedCharge([
                        'organization_id' => $company->getKey(), 'admission_id' => $admission->getKey(), 'day_no' => $day,
                        'day_on' => $start->toDateString(), 'bed_id' => $bed->getKey(), 'service_code' => $bed->service_code,
                    ]);
                    $charge->save();
                    $line = $this->charges->add($company, $unit, $admission->patient_id, 'inpatient', 'bed_day', (string) $charge->getKey(), $bed->service_code, 1000, $actor);
                    $charge->forceFill(['bill_id' => $line['bill_id'], 'bill_line_id' => $line['line_id']])->save();
                    if ($admission->bill_id === null) {
                        $this->store->query(Admission::class, $company)->whereKey($admission->getKey())->update(['bill_id' => $line['bill_id']]);
                        $admission->bill_id = $line['bill_id'];
                    }
                });
                $charged++;
            } catch (UniqueConstraintViolationException) {
                // Charged a moment ago by another run.
            }
        }

        return $charged;
    }
}
