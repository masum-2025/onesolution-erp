<?php

namespace Modules\Pharmacy\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Modules\Pharmacy\Exceptions\PharmacyException;
use Modules\Pharmacy\Models\Medicine;
use Modules\Pharmacy\Services\PharmacyStore;
use Modules\Pos\Models\Register;
use Modules\Pos\Services\Tills;

/**
 * The unit in the address (visible to the context) and its company; anything else is the same 404.
 */
trait FindsPharmacy
{
    use FindsVisibleOrganizations;

    /** @return array{0: Organization, 1: Organization} */
    protected function workplace(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, app(PharmacyStore::class)->companyOf($unit)];
    }

    /** A POS counter at the unit or below (POS's service reads it). */
    protected function counterIn(Organization $unit, Organization $company, string $id): Register
    {
        $register = app(Tills::class)->query(Register::class, $company)->whereKey($id)->first();
        if ($register === null || ! in_array($register->unit_id, app(Tills::class)->subtreeIds($unit), true)) {
            throw PharmacyException::notFound('register');
        }

        return $register;
    }

    protected function medicineIn(Organization $unit, Organization $company, string $id): Medicine
    {
        $store = app(PharmacyStore::class);

        return $store->query(Medicine::class, $company)->whereKey($id)->whereIn('unit_id', $store->subtreeIds($company))->first()
            ?? throw PharmacyException::notFound('medicine');
    }
}
