<?php

namespace Database\Seeders;

use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\Enums\RuleScope;
use App\Platform\Rules\Enums\RuleValueStatus;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Rules\RuleTargets;
use App\Platform\Rules\Services\RuleService;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Syncs rule definitions and seeds platform-, plan- and partner-level values from
 * database/seeders/data/rule-values.php. Safe to run again: values that
 * already exist at the same scope are left alone.
 */
class RulesSeeder extends Seeder
{
    public function run(RuleService $rules, RuleTargets $targets): void
    {
        $this->command?->call('rules:sync');

        foreach (require __DIR__.'/data/rule-values.php' as $entry) {
            // 'partner' => 'house' means the house partner (its slug comes from config).
            $partner = isset($entry['partner'])
                ? Partner::query()->where('slug', $entry['partner'] === 'house' ? config('tenancy.house_partner.slug') : $entry['partner'])->first()
                : null;
            if (isset($entry['partner']) && $partner === null) {
                continue;
            }

            $scope = match (true) {
                $partner !== null => RuleScope::Partner,
                isset($entry['plan']) => RuleScope::Plan,
                default => RuleScope::Platform,
            };
            $scopeId = $partner?->getKey() ?? $entry['plan'] ?? null;

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
                match ($scope) {
                    RuleScope::Partner => $targets->partner($partner),
                    RuleScope::Plan => $targets->plan($scopeId),
                    default => $targets->platform(),
                },
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
