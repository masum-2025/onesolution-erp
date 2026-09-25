<?php

namespace App\Platform\Modules\Http\Resources;

use App\Platform\Modules\ModuleDefinition;
use App\Platform\Modules\ResolvedModule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One module as seen from one organization: what it is, whether it is on,
 * and why (with the organization the decision comes from).
 *
 * Built from ['module' => ModuleDefinition, 'resolved' => ResolvedModule, 'names' => array<id, name>].
 */
class ModuleStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ModuleDefinition $module */
        $module = $this->resource['module'];
        /** @var ResolvedModule $resolved */
        $resolved = $this->resource['resolved'];
        /** @var array<string, string> $names */
        $names = $this->resource['names'];

        return [
            'key' => $module->key,
            'name' => $module->label(),
            'description' => __($module->description),
            'category' => $module->category,
            'requires' => $module->requires,
            'requires_consent' => $module->requiresConsent,
            'consent_terms_version' => $module->requiresConsent ? config('platform_modules.consent_terms_version') : null,
            'is_core' => $module->isCore,
            'enabled' => $resolved->enabled,
            'available' => $resolved->available,
            'reason' => $resolved->reason->value,
            'reason_message' => __('modules.reasons.'.$resolved->reason->value, [
                'modules' => implode(', ', $resolved->blockedBy),
            ]),
            'state' => $resolved->state->value,
            'source' => $resolved->source,
            'source_organization' => $this->organization($resolved->sourceOrganizationId, $names),
            'locked_by' => $this->organization($resolved->lockedByOrganizationId, $names),
            'locked_here' => $resolved->lockedHere,
            'blocked_by' => $resolved->blockedBy,
        ];
    }

    /**
     * @param  array<string, string>  $names
     * @return array{id: string, name: string|null}|null
     */
    private function organization(?string $id, array $names): ?array
    {
        return $id === null ? null : ['id' => $id, 'name' => $names[$id] ?? null];
    }
}
