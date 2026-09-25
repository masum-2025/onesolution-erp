<?php

namespace Database\Seeders;

use App\Platform\Packaging\Actions\ApplySectorPackage;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Seeder;

/**
 * Local-only: puts the demo group on the "business" plan and gives the demo
 * school its sector package (modules, settings, roles), as onboarding does.
 */
class DemoModulesSeeder extends Seeder
{
    public function run(ApplySectorPackage $apply): void
    {
        $partner = Partner::query()->where('is_house', true)->firstOrFail();

        $group = Organization::query()->where('partner_id', $partner->id)->where('type', OrganizationType::Group)->first();
        $company = Organization::query()->where('partner_id', $partner->id)->where('type', OrganizationType::Company)->first();

        if ($group === null || $company === null) {
            return;
        }

        $group->forceFill(['plan_key' => 'business'])->save();
        $apply->handle($company->fresh());
    }
}
