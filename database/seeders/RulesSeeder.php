<?php

namespace Database\Seeders;

use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\Enums\RuleScope;
use App\Platform\Rules\Enums\RuleValueStatus;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Rules\RuleTargets;
use App\Platform\Rules\Services\RuleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Syncs rule definitions and seeds platform- and plan-level values from
 * database/seeders/data/rule-values.php. Safe to run again: values that
 * already exist at the same scope are left alone.
 */
class RulesSeeder extends Seeder
{
    public function run(RuleService $rules, RuleTargets $targets): void
    {
        $this->command?->call('rules:sync');

        foreach (require __DIR__.'/data/rule-values.php' as $entry) {
            $scope = isset($entry['plan']) ? RuleScope::Plan : RuleScope::Platform;
            $scopeId = $entry['plan'] ?? null;

            $exists = RuleValue::query()
                ->where('rule_key', $entry['key'])
                ->where('scope_type', $scope)
                ->where('scope_id', $scopeId)
                ->where('country_code', $entry['country'] ?? null)
                ->where('status', RuleValueStatus::Active)
                ->exists();

            if ($exists) {
                continue;
            }

            $rules->set(
                $scopeId === null ? $targets->platform() : $targets->plan($scopeId),
                $entry['key'],
                RuleMode::Set,
                $entry['value'],
                $entry['reason'],
                countryCode: $entry['country'] ?? null,
                effectiveFrom: isset($entry['effective_from']) ? Carbon::parse($entry['effective_from'], 'UTC') : null,
                trusted: true,
            );
        }
    }
}
