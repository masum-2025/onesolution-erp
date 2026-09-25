<?php

namespace Database\Seeders;

use App\Platform\Tenancy\Enums\BillingMode;
use App\Platform\Tenancy\Enums\PartnerStatus;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Seeder;

/**
 * The "house" partner that owns clients who buy directly from us. Its name
 * and slug come from config (HOUSE_PARTNER_NAME / HOUSE_PARTNER_SLUG).
 */
class HousePartnerSeeder extends Seeder
{
    public function run(): void
    {
        $partner = Partner::query()->firstOrNew(['slug' => config('tenancy.house_partner.slug')]);

        $partner->fill([
            'name' => config('tenancy.house_partner.name'),
            'status' => $partner->status ?? PartnerStatus::Active,
            'billing_mode' => BillingMode::Direct,
        ]);
        $partner->forceFill(['is_house' => true])->save();
    }
}
