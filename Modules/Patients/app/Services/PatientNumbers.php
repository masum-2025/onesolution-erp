<?php

namespace Modules\Patients\Services;

use App\Platform\Tenancy\Models\Organization;
use Modules\Patients\Models\Sequence;

/**
 * Patient numbers (MRN): the company's prefix (rule patients.mrn_prefix) and a
 * counter for the whole company, never per branch: "P000123". Call inside the
 * caller's transaction (the counter row is locked).
 */
class PatientNumbers
{
    private const DIGITS = 6;

    public function __construct(private PatientsStore $store) {}

    public function next(Organization $company): string
    {
        $sequence = $this->store->query(Sequence::class, $company)->where('kind', 'mrn')->lockForUpdate()->first()
            ?? new Sequence(['organization_id' => $company->getKey(), 'kind' => 'mrn', 'last_number' => 0]);
        $sequence->last_number++;
        $sequence->save();

        return strtoupper((string) $this->store->rule('mrn_prefix', $company)).str_pad((string) $sequence->last_number, self::DIGITS, '0', STR_PAD_LEFT);
    }
}
