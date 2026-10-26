<?php

namespace Modules\Expenses\Http\Controllers\Concerns;

use App\Models\User;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Modules\Expenses\Exceptions\ExpensesException;
use Modules\Expenses\Models\Category;
use Modules\Expenses\Models\Claim;
use Modules\Expenses\Services\Place;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;

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

    /** The signed-in person as an employee of the company (their login linked in HRM). */
    protected function me(Organization $company, User $user): EmployeeRecord
    {
        return app(EmployeeDirectory::class)->forUser($company, $user) ?? throw ExpensesException::notLinked();
    }

    /** A claim of someone working in the unit or below it. */
    protected function claimIn(Organization $unit, Organization $company, string $id): Claim
    {
        $claim = app(Place::class)->query(Claim::class, $company)->whereKey($id)->first();
        if ($claim === null || ! in_array($claim->unit_id, app(Place::class)->subtreeIds($unit), true)) {
            throw ExpensesException::claimNotFound();
        }

        return $claim;
    }

    /** One of the signed-in employee's own claims. */
    protected function ownClaim(Organization $company, EmployeeRecord $me, string $id): Claim
    {
        return app(Place::class)->query(Claim::class, $company)->where('employee_id', $me->id)->whereKey($id)->first() ?? throw ExpensesException::claimNotFound();
    }

    protected function categoryIn(Organization $company, string $id): Category
    {
        return app(Place::class)->query(Category::class, $company)->whereKey($id)->first() ?? throw ExpensesException::categoryNotFound();
    }

    protected function unitOf(string $id): Organization
    {
        return Organization::query()->findOrFail($id);
    }
}
