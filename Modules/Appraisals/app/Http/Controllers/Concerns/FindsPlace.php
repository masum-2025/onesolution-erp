<?php

namespace Modules\Appraisals\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Modules\Appraisals\Exceptions\AppraisalsException;
use Modules\Appraisals\Models\Competency;
use Modules\Appraisals\Models\Cycle;
use Modules\Appraisals\Models\Review;
use Modules\Appraisals\Services\Place;

/**
 * The unit in the address (visible to the context), its company, and
 * records found only inside them; anything else is the same 404.
 */
trait FindsPlace
{
    use FindsVisibleOrganizations;

    /**
     * @return array{0: Organization, 1: Organization} The unit in the address and its company.
     */
    protected function place(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, app(Place::class)->companyOf($unit)];
    }

    /** A cycle of the unit or one below it. */
    protected function cycleIn(Organization $unit, Organization $company, string $id): Cycle
    {
        $cycle = app(Place::class)->query(Cycle::class, $company)->whereKey($id)->first();
        if ($cycle === null || ! in_array($cycle->unit_id, app(Place::class)->subtreeIds($unit), true)) {
            throw AppraisalsException::cycleNotFound();
        }

        return $cycle;
    }

    /** A review of the company (who may see it is decided by the caller). */
    protected function reviewIn(Organization $company, string $id): Review
    {
        return app(Place::class)->query(Review::class, $company)->whereKey($id)->first() ?? throw AppraisalsException::reviewNotFound();
    }

    protected function competencyIn(Organization $company, string $id): Competency
    {
        return app(Place::class)->query(Competency::class, $company)->whereKey($id)->first() ?? throw AppraisalsException::competencyNotFound();
    }

    protected function unitOf(string $id): Organization
    {
        return Organization::query()->findOrFail($id);
    }
}
