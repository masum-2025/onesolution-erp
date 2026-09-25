<?php

namespace Database\Factories;

use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds root groups only. Create children through
 * App\Platform\Tenancy\Actions\CreateOrganization so paths stay correct.
 *
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'partner_id' => Partner::factory(),
            'parent_id' => null,
            'depth' => 0,
            'type' => OrganizationType::Group,
            'name' => ['en' => fake()->company().' Group'],
            'status' => OrganizationStatus::Active,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Organization $organization) {
            $organization->id ??= $organization->newUniqueId();
            $organization->root_id = $organization->id;
            $organization->path = '/'.$organization->id.'/';
        });
    }
}
