<?php

namespace Modules\Pharmacy\Services;

use App\Models\User;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\Outpatient\Contracts\Prescriptions;
use Modules\Pharmacy\Exceptions\PharmacyException;
use Modules\Pharmacy\Models\SaleLink;

/**
 * Selling from a doctor's prescription at the counter: found through
 * Outpatient's Prescriptions contract (medicines, doctor and patient only),
 * only while Outpatient is on for the company, as far back as the rule
 * pharmacy.prescription_days at the counter's branch. Earlier sales of the
 * same prescription are shown as a warning, never a block.
 */
class PharmacyPrescriptions
{
    public function __construct(
        private PharmacyStore $store,
        private PharmacyRules $rules,
        private ModuleResolver $modules,
    ) {}

    public function on(Organization $company): bool
    {
        return $this->modules->isEnabled('outpatient', $company);
    }

    /** @return list<array<string, mixed>> */
    public function search(Organization $company, Organization $branch, string $term): array
    {
        return $this->source($company)->search($company, $branch, $term, $this->days($branch));
    }

    /**
     * The prescription with the counter's earlier sales of it; the viewer goes in the patient's "who looked" log.
     *
     * @return array<string, mixed>
     */
    public function show(Organization $company, Organization $branch, string $id, User $viewer): array
    {
        $found = $this->source($company)->find($company, $branch, $id, $this->days($branch), $viewer) ?? throw PharmacyException::notFound('prescription');
        $earlier = $this->store->query(SaleLink::class, $company)->where('consultation_id', $found['id'])->orderByDesc('sold_at')->limit(10)->get(['sale_number', 'sold_at']);

        return [...$found, 'earlier_sales' => $earlier->map(fn (SaleLink $link) => ['number' => $link->sale_number, 'sold_at' => $link->sold_at?->toIso8601String()])->values()->all()];
    }

    /**
     * The prescription a sale is made from, for its record (the doctor and patient as written there, not as typed).
     *
     * @return array{consultation_id: string, patient_name: string, prescriber_name: string, prescriber_bmdc: ?string}
     */
    public function forSale(Organization $company, Organization $branch, string $id): array
    {
        $found = $this->on($company) ? app(Prescriptions::class)->find($company, $branch, $id, $this->days($branch)) : null;
        if ($found === null) {
            throw ValidationException::withMessages(['prescription.consultation_id' => __('pharmacy::messages.validation.prescription')]);
        }

        return [
            'consultation_id' => $found['id'], 'patient_name' => mb_substr($found['patient_name'], 0, 120),
            'prescriber_name' => mb_substr($found['doctor_name'], 0, 120), 'prescriber_bmdc' => $found['doctor_registration_no'] !== null ? mb_substr($found['doctor_registration_no'], 0, 20) : null,
        ];
    }

    private function days(Organization $branch): int
    {
        return $this->rules->days('prescription_days', $branch);
    }

    private function source(Organization $company): Prescriptions
    {
        if (! $this->on($company)) {
            throw PharmacyException::prescriptionsOff();
        }

        return app(Prescriptions::class);
    }
}
