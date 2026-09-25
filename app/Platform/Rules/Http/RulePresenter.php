<?php

namespace App\Platform\Rules\Http;

use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\Enums\RuleValueStatus;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Rules\ResolvedRule;
use App\Platform\Rules\RuleDefinition;
use App\Platform\Rules\RuleTarget;
use Illuminate\Support\Collection;

/**
 * Shapes a rule for the rule editor: effective value, where it comes from,
 * limits from above, this level's own values, and whether it can be edited.
 */
class RulePresenter
{
    /**
     * @param  Collection<int, RuleValue>  $ownRows  This target's active and pending rows for the rule.
     * @return array<string, mixed>
     */
    public function present(RuleDefinition $rule, ResolvedRule $resolved, RuleTarget $target, Collection $ownRows, bool $moduleEnabled): array
    {
        $now = now();
        $editBlockedBy = match (true) {
            ! $rule->allowsLevel($target->scope) => 'level_not_allowed',
            ! $moduleEnabled => 'module_disabled',
            $resolved->isLockedByAncestor() => 'locked_by_parent',
            default => null,
        };

        $current = $ownRows->filter(fn (RuleValue $row) => $row->isEffectiveAt($now));

        return [
            'key' => $rule->key,
            'module' => $rule->moduleKey,
            'category' => $rule->category,
            'label' => $rule->label(),
            'description' => __($rule->description),
            'type' => $rule->type->value,
            'schema' => $rule->effectiveSchema(),
            'default' => $rule->default,
            'value' => $resolved->value,
            'source' => $resolved->sourceLevel === null ? null : [
                'level' => $resolved->sourceLevel,
                'id' => $resolved->sourceScopeId,
                'name' => $resolved->sourceName,
            ],
            'locked_by' => $resolved->isLockedByAncestor() ? [
                'level' => $resolved->lockedByLevel,
                'id' => $resolved->lockedByScopeId,
                'name' => $resolved->lockedByName,
            ] : null,
            'locked_here' => $resolved->lockedHere,
            'constraints' => $resolved->constraints ?: null,
            'fell_back' => $resolved->fellBack,
            'own' => [
                'value' => $this->row($current->first(fn (RuleValue $row) => $row->mode !== RuleMode::Constrain)),
                'constraint' => $this->row($current->first(fn (RuleValue $row) => $row->mode === RuleMode::Constrain)),
                'scheduled' => $ownRows
                    ->filter(fn (RuleValue $row) => $row->status === RuleValueStatus::Active && $row->effective_from?->gt($now))
                    ->map(fn (RuleValue $row) => $this->row($row))->values()->all(),
                'pending_approval' => $ownRows
                    ->filter(fn (RuleValue $row) => $row->status === RuleValueStatus::PendingApproval)
                    ->map(fn (RuleValue $row) => $this->row($row))->values()->all(),
            ],
            'country_specific' => $rule->countrySpecific,
            'requires_approval' => $rule->needsApproval(),
            'overridable_levels' => array_map(fn ($level) => $level->value, $rule->overridableLevels),
            'editable' => $editBlockedBy === null,
            'edit_blocked_by' => $editBlockedBy,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function row(?RuleValue $row): ?array
    {
        return $row === null ? null : [
            'id' => $row->id,
            'mode' => $row->mode->value,
            'value' => $row->value,
            'country_code' => $row->country_code,
            'version' => $row->version,
            'status' => $row->status->value,
            'effective_from' => $row->effective_from?->toIso8601String(),
            'effective_to' => $row->effective_to?->toIso8601String(),
            'reason' => $row->reason,
            'created_by' => $row->created_by,
        ];
    }
}
