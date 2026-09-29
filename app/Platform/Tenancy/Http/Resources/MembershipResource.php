<?php

namespace App\Platform\Tenancy\Http\Resources;

use App\Platform\Tenancy\Models\OrganizationMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrganizationMembership
 */
class MembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                // Whether they sign in with a second step (Phase 8-1); never which one.
                'two_factor' => $this->user->hasTwoFactor(),
            ]),
            'membership_type' => $this->membership_type->value,
            'access_scope' => $this->access_scope->value,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->displayName(),
                'organization_id' => $role->organization_id,
            ])->sortBy('name')->values()->all()),
            'is_primary' => $this->is_primary,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
