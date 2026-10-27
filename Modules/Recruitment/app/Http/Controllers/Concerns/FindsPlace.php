<?php

namespace Modules\Recruitment\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Modules\Recruitment\Exceptions\RecruitmentException;
use Modules\Recruitment\Models\Application;
use Modules\Recruitment\Models\Interview;
use Modules\Recruitment\Models\Opening;
use Modules\Recruitment\Services\Place;

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

    /** An opening at the unit or below it. */
    protected function openingIn(Organization $unit, Organization $company, string $id): Opening
    {
        $opening = app(Place::class)->query(Opening::class, $company)->whereKey($id)->first();
        if ($opening === null || ! in_array($opening->unit_id, app(Place::class)->subtreeIds($unit), true)) {
            throw RecruitmentException::openingNotFound();
        }

        return $opening;
    }

    /** An application (never one still unconfirmed) at the unit or below it. */
    protected function applicationIn(Organization $unit, Organization $company, string $id): Application
    {
        $application = app(Place::class)->query(Application::class, $company)->whereKey($id)->where('status', '!=', Application::UNCONFIRMED)->first();
        if ($application === null || ! in_array($application->unit_id, app(Place::class)->subtreeIds($unit), true)) {
            throw RecruitmentException::applicationNotFound();
        }

        return $application;
    }

    protected function interviewIn(Organization $company, string $id): Interview
    {
        return app(Place::class)->query(Interview::class, $company)->whereKey($id)->first() ?? throw RecruitmentException::interviewNotFound();
    }

    protected function unitOf(string $id): Organization
    {
        return Organization::query()->findOrFail($id);
    }
}
