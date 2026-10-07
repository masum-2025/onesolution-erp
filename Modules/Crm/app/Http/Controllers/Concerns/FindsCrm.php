<?php

namespace Modules\Crm\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Modules\Crm\Exceptions\CrmException;
use Modules\Crm\Services\Crm;

/**
 * The unit in the address (visible to the context), its company, and CRM
 * records only of that unit and the units below it; anything else is the
 * same 404 (another company's or another branch's record looks unknown).
 */
trait FindsCrm
{
    use FindsVisibleOrganizations;

    /**
     * @return array{0: Organization, 1: Organization}
     */
    protected function workplace(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, app(Crm::class)->companyOf($unit)];
    }

    /** @return list<string> */
    protected function unitIds(Organization $unit): array
    {
        return app(Crm::class)->subtreeIds($unit);
    }

    /**
     * A record of the company that belongs to the unit or a unit below it.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function recordIn(string $class, Organization $unit, Organization $company, string $id, string $what): Model
    {
        $record = app(Crm::class)->query($class, $company)->whereKey($id)->first();
        if ($record === null || ! in_array($record->unit_id, $this->unitIds($unit), true)) {
            throw CrmException::notFound($what);
        }

        return $record;
    }

    /** The unit a new record belongs to: the one asked for (here or below), else the one in the address. */
    protected function unitFor(Organization $unit, ?string $asked): string
    {
        if ($asked === null || $asked === '') {
            return $unit->getKey();
        }
        if (! in_array($asked, $this->unitIds($unit), true)) {
            throw CrmException::notFound('unit');
        }

        return $asked;
    }

    protected function unitOf(string $id): Organization
    {
        return Organization::query()->findOrFail($id);
    }
}
