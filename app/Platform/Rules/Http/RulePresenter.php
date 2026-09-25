<?php

namespace App\Platform\Rules\Http;

use App\Models\User;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\Enums\RuleType;
use App\Platform\Rules\Enums\RuleValueStatus;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Rules\ResolvedRule;
use App\Platform\Rules\RuleCatalog;
use App\Platform\Rules\RuleDefinition;
use App\Platform\Rules\RuleTarget;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Shapes a rule for the rule editor: effective value, where it comes from,
 * limits from above, this level's own values, and whether it can be edited.
 */
class RulePresenter
{
    public function __construct(private ModuleRegistry $registry) {}

    public function moduleName(RuleDefinition $rule): string
    {
        return $rule->moduleKey === RuleCatalog::CORE_MODULE
            ? __('rules.core_module_name')
            : $this->registry->get($rule->moduleKey)->label();
    }

    /**
     * @param  Collection<int, RuleValue>  $ownRows  This target's active and pending rows for the rule.
     * @return array<string, mixed>
     */
    public function present(RuleDefinition $rule, ResolvedRule $resolved, RuleTarget $target, Collection $ownRows, bool $moduleEnabled, bool $permitted = true): array
    {
        $now = now();
        $editBlockedBy = match (true) {
            ! $rule->allowsLevel($target->scope) => 'level_not_allowed',
            ! $permitted => 'no_permission',
            ! $moduleEnabled => 'module_disabled',
            $resolved->isLockedByAncestor() => 'locked_by_parent',
            default => null,
        };

        $current = $ownRows->filter(fn (RuleValue $row) => $row->isEffectiveAt($now));

        return [
            'key' => $rule->key,
            'module' => $rule->moduleKey,
            'module_name' => $this->moduleName($rule),
            'category' => $rule->category,
            'category_label' => $this->categoryLabel($rule),
            'label' => $rule->label(),
            'options' => $this->options($rule),
            'description' => __($rule->description),
            'type' => $rule->type->value,
            // Flat schema for form hints (min/max, pattern, enum, table columns);
            // the server still validates against the full effectiveSchema().
            'schema' => $this->formSchema($rule),
            'nullable' => $rule->nullable,
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
     * Category name from the rule's own translation file
     * ("{module}::rules.categories.{category}"), or a readable fallback.
     */
    public function categoryLabel(RuleDefinition $rule): string
    {
        $key = Str::before($rule->label, 'rules.').'rules.categories.'.$rule->category;

        return $this->translated($key) ?? Str::headline($rule->category);
    }

    /**
     * Choices for enum / multi_enum rules, labelled from
     * "{module}::rules.{rule}.options.{value}".
     *
     * @return list<array{value: string, label: string}>|null
     */
    private function options(RuleDefinition $rule): ?array
    {
        $schema = $this->formSchema($rule);
        $values = match ($rule->type) {
            RuleType::Enum => $schema['enum'] ?? null,
            RuleType::MultiEnum => $schema['items']['enum'] ?? null,
            default => null,
        };

        if (! is_array($values)) {
            return null;
        }

        $base = Str::beforeLast($rule->label, '.label').'.options.';

        return array_map(fn ($value) => [
            'value' => (string) $value,
            'label' => $this->translated($base.$value) ?? Str::headline((string) $value),
        ], array_values($values));
    }

    /**
     * The type's base schema with the rule's own schema on top, as one object.
     *
     * @return array<string, mixed>
     */
    private function formSchema(RuleDefinition $rule): array
    {
        return array_replace_recursive($rule->type->baseSchema(), $rule->schema);
    }

    private function translated(string $key): ?string
    {
        $text = __($key);

        return is_string($text) && $text !== $key ? $text : null;
    }

    /**
     * Display names for the people shown next to rule changes (no emails).
     *
     * @param  Collection<int, string|null>  $userIds
     * @return array<string, string>
     */
    public function userNames(Collection $userIds): array
    {
        $ids = $userIds->filter()->unique()->values();

        return $ids->isEmpty() ? [] : User::query()->whereKey($ids)->pluck('name', 'id')->all();
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
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
