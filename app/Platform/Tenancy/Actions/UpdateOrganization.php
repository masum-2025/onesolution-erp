<?php

namespace App\Platform\Tenancy\Actions;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use BackedEnum;
use Illuminate\Support\Arr;

/**
 * Updates an organization's own data. Tree position is changed only by
 * HierarchyService::move().
 */
class UpdateOrganization
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  Validated, fillable attributes only.
     */
    public function handle(Organization $organization, array $attributes, ?User $actor = null): Organization
    {
        // A new name replaces every language (a language left out is removed).
        if (array_key_exists('name', $attributes)) {
            $organization->putTexts('name', (array) $attributes['name']);
            unset($attributes['name']);
        }

        $organization->fill($attributes);

        if (! $organization->isDirty()) {
            return $organization;
        }

        $changed = array_keys($organization->getDirty());
        $old = Arr::only($organization->getOriginal(), $changed);

        $organization->save();

        $this->audit->record(
            action: 'organization.updated',
            target: $organization,
            old: array_map(fn ($value) => $value instanceof BackedEnum ? $value->value : $value, $old),
            new: Arr::only($organization->attributesToArray(), $changed),
            actor: $actor,
            partnerId: $organization->partner_id,
        );

        return $organization;
    }
}
