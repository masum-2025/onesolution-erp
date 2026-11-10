<?php

namespace Modules\PatientBilling\Contracts;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;

/**
 * How other departments (diagnostics, outpatient, inpatient) put a charge on
 * a patient's bill, never through the billing tables. The charge lands on the
 * patient's open bill at the unit (one is opened when there is none), priced
 * by the service code at today's price. The same source record is charged
 * once: calling again returns the same line.
 */
interface PatientCharges
{
    /**
     * @return array{bill_id: string, line_id: string}
     */
    public function add(Organization $company, Organization $unit, string $patientId, string $sourceModule, string $sourceType, string $sourceId, string $serviceCode, int $quantityMilli, User $actor): array;
}
