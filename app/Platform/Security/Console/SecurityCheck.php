<?php

namespace App\Platform\Security\Console;

use App\Platform\Security\Checks\ProductionReadiness;
use Illuminate\Console\Command;

/**
 * Run on every deploy: fails (exit code 1) in production while any setting
 * is unsafe, so the deploy stops. Elsewhere it lists what production needs.
 */
class SecurityCheck extends Command
{
    protected $signature = 'security:check {--strict : Fail on problems outside production too}';

    protected $description = 'Check that the server settings are safe for production';

    public function handle(ProductionReadiness $readiness): int
    {
        $results = $readiness->run();
        $failed = array_filter($results, fn (array $result) => ! $result['passed']);

        $this->table(['Check', 'Result', 'What to do'], array_map(fn (array $result) => [
            $result['key'],
            $result['passed'] ? 'ok' : 'FIX',
            $result['passed'] ? '' : $result['fix'],
        ], $results));

        if ($failed === []) {
            $this->info('All checks passed.');

            return self::SUCCESS;
        }

        $enforced = app()->isProduction() || $this->option('strict');
        $message = count($failed).' of '.count($results).' checks need attention.';
        $enforced ? $this->error($message) : $this->warn($message.' (Not production: shown for information.)');

        return $enforced ? self::FAILURE : self::SUCCESS;
    }
}
