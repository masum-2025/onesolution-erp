<?php

namespace Database\Factories;

use App\Platform\Tenancy\Enums\BillingMode;
use App\Platform\Tenancy\Enums\PartnerStatus;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Partner>
 */
class PartnerFactory extends Factory
{
    protected $model = Partner::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'status' => PartnerStatus::Active,
            'is_house' => false,
            'billing_mode' => BillingMode::Wholesale,
            'settings' => null,
        ];
    }

    public function house(): static
    {
        return $this->state(fn () => ['is_house' => true, 'billing_mode' => BillingMode::Direct]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => PartnerStatus::Suspended]);
    }
}
