<?php

namespace App\Platform\Rules\Console;

use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\Exceptions\RuleException;
use App\Platform\Rules\RuleTargets;
use App\Platform\Rules\Services\RuleService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Platform operators set platform- and plan-level values here (there is no
 * platform admin UI yet). Audited like every other change.
 *
 *   php artisan rules:set payroll.overtime_multiplier '"2.0"' --country=BD --reason="Labour Act s.108"
 *   php artisan rules:set offline_mode.max_cached_records 20000 --plan=business --reason="Plan default"
 */
class SetRuleValue extends Command
{
    protected $signature = 'rules:set
        {key : Rule key, e.g. payroll.overtime_multiplier}
        {value : JSON value, e.g. 15, "\"2.0\"", "[\"fri\"]"}
        {--plan= : Store at this plan instead of platform level}
        {--country= : ISO country code for country-specific rules}
        {--mode=set : set, lock or constrain}
        {--effective-from= : Date/time the value takes effect (UTC)}
        {--reason= : Why (required, stored in the audit log)}';

    protected $description = 'Set a platform- or plan-level rule value';

    public function handle(RuleService $rules, RuleTargets $targets): int
    {
        $reason = (string) $this->option('reason');
        if (mb_strlen($reason) < 5) {
            $this->error('Give a --reason of at least 5 characters.');

            return self::INVALID;
        }

        $value = json_decode((string) $this->argument('value'), true, flags: JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('The value must be valid JSON (strings need quotes).');

            return self::INVALID;
        }

        $mode = RuleMode::tryFrom((string) $this->option('mode'));
        if ($mode === null) {
            $this->error('--mode must be set, lock or constrain.');

            return self::INVALID;
        }

        $target = $this->option('plan') ? $targets->plan((string) $this->option('plan')) : $targets->platform();
        $effectiveFrom = $this->option('effective-from') ? Carbon::parse((string) $this->option('effective-from'), 'UTC') : null;

        try {
            $row = $rules->set(
                $target,
                (string) $this->argument('key'),
                $mode,
                $value,
                $reason,
                countryCode: $this->option('country') ? strtoupper((string) $this->option('country')) : null,
                effectiveFrom: $effectiveFrom,
                trusted: true,
            );
        } catch (RuleException $e) {
            $this->error((string) $e->render()->getData(true)['message']);

            return self::FAILURE;
        }

        $this->info("Stored {$row->rule_key} v{$row->version} at {$row->scope_type->value} level.");

        return self::SUCCESS;
    }
}
