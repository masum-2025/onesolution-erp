<?php

namespace App\Platform\Tenancy\Http\Resources;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a partner sees about its clients: account metadata only, never
 * business data.
 *
 * @mixin Organization
 */
class PartnerOrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->resource->texts('name'),
            'display_name' => $this->displayName(),
            'parent_id' => $this->parent_id,
            'depth' => $this->depth,
            'sector_key' => $this->sector_key,
            'country_code' => $this->country_code,
            'plan_key' => $this->plan_key,
            // The plan the subscription is on (top organizations only; the default when none is set).
            'subscription_plan' => $this->parent_id === null ? ($this->plan_key ?? config('tenancy.defaults.plan_key')) : null,
            'currency_code' => $this->currency_code,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
