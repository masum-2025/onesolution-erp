<?php

namespace Database\Seeders;

use App\Platform\Modules\Services\ModuleToggleService;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Seeder;

/**
 * Local-only: puts the demo group on the "business" plan and turns on
 * payroll (with HR and attendance) for the demo school.
 */
class DemoModulesSeeder extends Seeder
{
    public function run(ModuleToggleService $toggles): void
    {
        $partner = Partner::query()->where('is_house', true)->firstOrFail();

        $group = Organization::query()->where('partner_id', $partner->id)->where('type', OrganizationType::Group)->first();
        $company = Organization::query()->where('partner_id', $partner->id)->where('type', OrganizationType::Company)->first();

        if ($group === null || $company === null) {
            return;
        }

        $group->forceFill(['plan_key' => 'business'])->save();
        $toggles->enable($company, 'payroll', 'Demo data');
    }
}
