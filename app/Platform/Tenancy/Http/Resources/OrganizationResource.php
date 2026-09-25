<?php

namespace App\Platform\Tenancy\Http\Resources;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Organization
 */
class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'display_name' => $this->displayName(),
            'parent_id' => $this->parent_id,
            'depth' => $this->depth,
            'sector_key' => $this->sector_key,
            // Own values only; null = inherited. See /settings for effective values.
            'country_code' => $this->country_code,
            'default_locale' => $this->default_locale,
            'timezone' => $this->timezone,
            'currency_code' => $this->currency_code,
            'region' => $this->region,
            'status' => $this->status->value,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
