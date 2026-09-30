<?php

namespace App\Platform\Tenancy\Databases\Console;

use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Databases\TenantTables;
use Illuminate\Console\Command;

/**
 * Create or update the business-data tables in every dedicated / regional
 * database. The main database gets them from the normal "migrate".
 */
class TenantsMigrate extends Command
{
    protected $signature = 'tenants:migrate
        {--database=* : Only these tenant databases (default: all configured)}';

    protected $description = 'Run the tenant (business data) migrations on every dedicated and regional database';

    public function handle(TenantDatabases $databases, TenantTables $tables): int
    {
        $names = $this->option('database') ?: $databases->names();

        if ($names === []) {
            $this->line('No tenant databases are configured (TENANT_DATABASES). Nothing to do.');

            return self::SUCCESS;
        }

        foreach ($names as $name) {
            $connection = $databases->connectionFor((string) $name);
            $this->info("Migrating {$name} ({$connection})");

            $status = $this->call('migrate', [
                '--database' => $connection,
                '--path' => $tables->migrationPaths(),
                '--realpath' => true,
                '--force' => true,
            ]);

            if ($status !== self::SUCCESS) {
                return $status;
            }
        }

        return self::SUCCESS;
    }
}
