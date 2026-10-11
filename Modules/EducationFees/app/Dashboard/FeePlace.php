<?php

namespace Modules\EducationFees\Dashboard;

use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Modules\EducationFees\Services\FeeOffice;

/** The institution and the units of the context (a group has no fees of its own). */
final class FeePlace
{
    /** @return array{0: Organization, 1: list<string>}|null */
    public static function of(CurrentContext $context): ?array
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group || $unit->type === OrganizationType::Department) {
            return null;
        }
        $office = app(FeeOffice::class);

        return [$office->companyOf($unit), $office->subtreeIds($unit)];
    }
}
