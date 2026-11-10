<?php

namespace Modules\Patients\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Modules\Patients\Services\PatientsStore;

/**
 * The unit in the address (visible to the context) and its company; anything else is the same 404.
 */
trait FindsPatients
{
    use FindsVisibleOrganizations;

    /** @return array{0: Organization, 1: Organization} */
    protected function workplace(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, app(PatientsStore::class)->companyOf($unit)];
    }
}
