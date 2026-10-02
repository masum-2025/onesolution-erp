<?php

namespace Modules\Hrm\Console;

use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Modules\Hrm\Enums\ImportStatus;
use Modules\Hrm\Models\EmployeeImport;
use Modules\Hrm\Services\EmployeeImporter;

/**
 * Checked imports nobody started (or that stayed queued) are cancelled after
 * a week, which wipes the employee details kept for them. Only those
 * details go; the import and its counts stay. Runs in every client database.
 */
class PruneImports extends Command
{
    protected $signature = 'hrm:prune-imports {--days=7 : Age in days of an import left unstarted}';

    protected $description = 'Cancel stale employee imports and wipe the details kept for them';

    public function handle(TenantDatabases $databases, EmployeeImporter $importer): int
    {
        $before = now()->subDays(max(1, (int) $this->option('days')));
        $count = 0;

        foreach ([null, ...$databases->names()] as $database) {
            $databases->onConnection($databases->connectionFor($database), function () use ($before, $importer, &$count) {
                EmployeeImport::query()->withoutGlobalScope(OrganizationScope::class)
                    ->whereIn('status', [ImportStatus::Checked->value, ImportStatus::Queued->value])
                    ->where('created_at', '<', $before)
                    ->each(function (EmployeeImport $import) use ($importer, &$count) {
                        $importer->finish($import, ImportStatus::Cancelled);
                        $count++;
                    });
            });
        }

        $this->info("Cancelled {$count} stale import(s).");

        return self::SUCCESS;
    }
}
