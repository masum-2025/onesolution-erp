<?php

namespace Modules\Education\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Modules\Education\Exceptions\EducationException;
use Modules\Education\Services\Education;

/**
 * The unit in the address (visible to the context), its institution, and
 * records only of that unit and the campuses below it; anything else is the
 * same 404 (another institution's or another campus's record looks unknown).
 */
trait FindsEducation
{
    use FindsVisibleOrganizations;

    /**
     * @return array{0: Organization, 1: Organization}
     */
    protected function workplace(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, app(Education::class)->companyOf($unit)];
    }

    /** @return list<string> */
    protected function unitIds(Organization $unit): array
    {
        return app(Education::class)->subtreeIds($unit);
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
        $record = app(Education::class)->query($class, $company)->whereKey($id)->first();
        if ($record === null || ! in_array($record->unit_id, $this->unitIds($unit), true)) {
            throw EducationException::notFound($what);
        }

        return $record;
    }

    /** The campus a new record belongs to: the one asked for (here or below), else the one in the address. */
    protected function unitFor(Organization $unit, ?string $asked): string
    {
        if ($asked === null || $asked === '') {
            return $unit->getKey();
        }
        if (! in_array($asked, $this->unitIds($unit), true)) {
            throw EducationException::notFound('unit');
        }

        return $asked;
    }
}
