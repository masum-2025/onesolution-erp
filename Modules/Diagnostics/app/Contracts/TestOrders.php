<?php

namespace Modules\Diagnostics\Contracts;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;

/**
 * How other modules (outpatient, inpatient) send tests to the lab, never
 * through the diagnostics tables: by test codes, for a patient, from one of
 * their records. The same source record makes one order (calling again
 * returns it). The tests are billed on the patient's bill as usual.
 */
interface TestOrders
{
    /**
     * @param  list<string>  $testCodes
     * @return array{order_id: string, number: string}
     */
    public function place(Organization $company, Organization $unit, string $patientId, array $testCodes, string $sourceModule, string $sourceId, User $actor): array;

    /**
     * Active tests for a picker: id, code, name (reader's language), sample type.
     *
     * @return list<array{id: string, code: string, name: string, sample_type: string}>
     */
    public function catalogue(Organization $company): array;
}
