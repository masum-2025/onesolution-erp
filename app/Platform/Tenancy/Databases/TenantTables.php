<?php

namespace App\Platform\Tenancy\Databases;

/**
 * The tables that follow their client into a dedicated or regional database:
 * exactly those created by migrations in a "tenant" migrations folder (core
 * and every module). Each has an organization_id column and no foreign key
 * to a platform table (tests/Feature/Architecture/TenantDatabaseTablesTest).
 */
class TenantTables
{
    /**
     * @return list<string> Existing tenant migration folders.
     */
    public function migrationPaths(): array
    {
        $paths = [
            ...(array) config('tenant_databases.migration_paths', []),
            ...(glob(base_path('Modules/*/database/migrations/tenant'), GLOB_ONLYDIR) ?: []),
        ];

        return array_values(array_unique(array_filter($paths, 'is_dir')));
    }

    /**
     * @return list<string> Migration files, in the order they run.
     */
    public function migrationFiles(): array
    {
        $files = [];

        foreach ($this->migrationPaths() as $path) {
            foreach (glob($path.'/*.php') ?: [] as $file) {
                $files[basename($file)] = $file;
            }
        }

        ksort($files);

        return array_values($files);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        $tables = [];

        foreach ($this->migrationFiles() as $file) {
            preg_match_all("/Schema::create\\(\\s*'([a-z0-9_]+)'/", (string) file_get_contents($file), $matches);
            array_push($tables, ...$matches[1]);
        }

        return array_values(array_unique($tables));
    }
}
