<?php

namespace Modules\CourseRegistration\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Modules\CourseRegistration\Exceptions\RegistrationException;
use Modules\CourseRegistration\Services\Campus;

/**
 * The unit in the address (visible to the context), its institution, and
 * records only of that unit and the campuses below it; anything else is the
 * same 404 (another institution's or another campus's record looks unknown).
 */
trait FindsCampus
{
    use FindsVisibleOrganizations;

    /**
     * @return array{0: Organization, 1: Organization}
     */
    protected function workplace(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, app(Campus::class)->companyOf($unit)];
    }

    /** @return list<string> */
    protected function unitIds(Organization $unit): array
    {
        return app(Campus::class)->subtreeIds($unit);
    }

    /**
     * A record of the institution at the unit or a campus below it.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function recordIn(string $class, Organization $unit, Organization $company, string $id, string $what): Model
    {
        $record = app(Campus::class)->query($class, $company)->whereKey($id)->first();
        if ($record === null || ! in_array($record->unit_id, $this->unitIds($unit), true)) {
            throw RegistrationException::notFound($what);
        }

        return $record;
    }
}
