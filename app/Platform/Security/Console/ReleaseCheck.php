<?php

namespace App\Platform\Security\Console;

use App\Platform\Monitoring\HealthReport;
use App\Platform\Security\Checks\ProductionReadiness;
use App\Platform\Security\Findings;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Databases\TenantPlacements;
use App\Platform\Tenancy\Databases\TenantTables;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * The release gate (Phase 11, docs/release-checklist.md): run on the server
 * about to be released. Fails when a critical or high finding is open, the
 * tracker is malformed, migrations are pending, a tenant database misses its
 * tables, or the last backup or restore drill is not good; with --production
 * also when the server settings are unsafe (security:check).
 */
class ReleaseCheck extends Command
{
    protected $signature = 'release:check {--production : Also check the server settings for production}';

    protected $description = 'Check that this build and server may be released';

    public function handle(Findings $findings, HealthReport $health, Migrator $migrator, TenantDatabases $databases, TenantPlacements $placements, TenantTables $tables, ProductionReadiness $readiness): int
    {
        $checks = [];

        $problems = $findings->problems();
        $checks[] = ['Remediation tracker is valid', $problems === [], implode(' ', $problems)];

        $blocking = $findings->blocking();
        $checks[] = ['No open critical or high findings', $blocking === [], implode(', ', array_column($blocking, 'id'))];

        $pending = $this->pendingMigrations($migrator, $tables);
        $checks[] = ['No pending migrations', $pending === [], implode(', ', array_slice($pending, 0, 5))];

        foreach ($databases->names() as $name) {
            try {
                $placements->assertMigrated($databases->connectionFor($name));
                $checks[] = ["Tenant database {$name} has its tables", true, ''];
            } catch (Throwable $exception) {
                $checks[] = ["Tenant database {$name} has its tables", false, class_basename($exception)];
            }
        }

        $report = $health->run()['checks'];
        foreach (['backup' => 'Recent backup', 'restore_drill' => 'Last restore drill passed'] as $key => $label) {
            $checks[] = [$label, $report[$key]['status'] === 'ok', (string) ($report[$key]['detail'] ?? $report[$key]['value'] ?? '')];
        }

        if ($this->option('production')) {
            foreach ($readiness->run() as $check) {
                $checks[] = ['Server: '.$check['key'], $check['passed'], $check['passed'] ? '' : (string) $check['fix']];
            }
        }

        $this->table(['Check', 'Result', 'Details'], array_map(fn (array $check) => [$check[0], $check[1] ? 'ok' : 'FAIL', $check[2]], $checks));

        $failed = array_filter($checks, fn (array $check) => ! $check[1]);
        if ($failed !== []) {
            $this->error(count($failed).' check(s) failed: do not release. See docs/release-checklist.md.');

            return self::FAILURE;
        }

        $this->info('All release checks passed.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function pendingMigrations(Migrator $migrator, TenantTables $tables): array
    {
        if (! $migrator->repositoryExists()) {
            return ['(the migrations table is missing)'];
        }

        $paths = array_unique([database_path('migrations'), ...$migrator->paths(), ...$tables->migrationPaths()]);
        $files = $migrator->getMigrationFiles($paths);

        return array_values(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
    }
}
