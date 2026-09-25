<?php

namespace App\Platform\Rules\Console;

use App\Platform\Rules\Models\RuleDefinitionRecord;
use App\Platform\Rules\RuleCatalog;
use Illuminate\Console\Command;

/**
 * Mirror the in-code rule catalog into rule_definitions. Run on every deploy.
 * Rules removed from code are marked deprecated; their stored values stay.
 */
class SyncRuleDefinitions extends Command
{
    protected $signature = 'rules:sync';

    protected $description = 'Sync rule definitions from module manifests into the database';

    public function handle(RuleCatalog $catalog): int
    {
        foreach ($catalog->all() as $rule) {
            RuleDefinitionRecord::query()->updateOrCreate(['key' => $rule->key], [
                'module_key' => $rule->moduleKey,
                'type' => $rule->type->value,
                'schema' => $rule->schema,
                'default_value' => $rule->default,
                'nullable' => $rule->nullable,
                'label' => $rule->label,
                'description' => $rule->description,
                'overridable_levels' => array_map(fn ($level) => $level->value, $rule->overridableLevels),
                'edit_permission' => $rule->editPermission,
                'requires_approval' => $rule->requiresApproval,
                'sensitive' => $rule->sensitive,
                'country_specific' => $rule->countrySpecific,
                'category' => $rule->category,
                'sort_order' => $rule->sortOrder,
                'deprecated_at' => null,
            ]);
        }

        $deprecated = RuleDefinitionRecord::query()
            ->whereNotIn('key', array_keys($catalog->all()))
            ->whereNull('deprecated_at')
            ->update(['deprecated_at' => now()]);

        $this->info(count($catalog->all()).' rule definitions synced, '.$deprecated.' deprecated.');

        return self::SUCCESS;
    }
}
