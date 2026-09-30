<?php

namespace App\Platform\Security\Backups;

use App\Platform\Audit\AuditLogger;
use App\Platform\Security\Backups\Contracts\DatabaseDumper;
use App\Platform\Tenancy\Databases\TenantDatabases;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PharData;
use RecursiveIteratorIterator;
use Throwable;

/**
 * The monthly restore drill (Phase 8-2): restores a backup set into a
 * separate staging database, then checks it. Proves that backups can be read
 * with the keys we hold and that they contain what they claim.
 *
 * Passes when: the manifest signature, both checksums and the decryption hold;
 * every backed-up table exists after restore; no table that had rows comes
 * back empty; and the file archive holds as many files as recorded. Row count
 * differences on non-empty tables are reported, not failed: the counts are
 * taken a moment before the dump's snapshot, so a busy database moves a little.
 */
class RestoreDrill
{
    public const CONNECTION = 'restore_drill';

    public function __construct(
        private BackupService $backups,
        private DatabaseDumper $dumper,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed> The drill report.
     */
    public function run(?string $setId = null): array
    {
        $this->configureConnection();

        $started = now('UTC');
        $manifest = $this->backups->manifest($setId);
        $work = $this->backups->workDir('drill-'.$manifest['id']);

        try {
            $this->backups->open($manifest['id'], BackupService::DATABASE_FILE, $manifest['database'], "{$work}/database.sql");

            // The staging database is emptied, then filled from the backup.
            Schema::connection(self::CONNECTION)->dropAllTables();
            $this->dumper->restore(self::CONNECTION, "{$work}/database.sql");

            $tables = $this->compareTables($manifest['tables'], self::CONNECTION);
            $files = $this->checkFiles($manifest, $work);

            // Each dedicated / regional client database into its own staging database (Phase 10).
            $tenantDatabases = [];
            foreach ($manifest['tenant_databases'] ?? [] as $name => $entry) {
                $connection = $this->configureConnection((string) $name);
                $this->backups->open($manifest['id'], BackupService::tenantFile((string) $name), $entry['database'], "{$work}/tenant.sql");
                Schema::connection($connection)->dropAllTables();
                $this->dumper->restore($connection, "{$work}/tenant.sql");
                @unlink("{$work}/tenant.sql");
                $tenantDatabases[$name] = $this->compareTables($entry['tables'], $connection);
                DB::purge($connection);
            }
        } catch (Throwable $exception) {
            // Only our own messages are safe to keep; others could quote data or SQL.
            $this->record($manifest['id'], false, [
                'error' => $exception instanceof BackupException ? $exception->getMessage() : class_basename($exception),
            ]);

            throw $exception;
        } finally {
            File::deleteDirectory($work);
            DB::purge(self::CONNECTION);
        }

        $intact = fn (array $compared) => $compared['missing'] === [] && $compared['emptied'] === [];
        $passed = $intact($tables) && $files['passed'] && array_filter($tenantDatabases, fn (array $compared) => ! $intact($compared)) === [];

        $report = [
            'set' => $manifest['id'],
            'set_created_at' => $manifest['created_at'],
            'key_version' => $manifest['key_version'],
            'drill_database' => config('database.connections.'.self::CONNECTION.'.database'),
            'started_at' => $started->toIso8601String(),
            'finished_at' => now('UTC')->toIso8601String(),
            'passed' => $passed,
            'tables' => $tables,
            'files' => $files,
            'tenant_databases' => $tenantDatabases,
        ];

        $this->backups->disk()->put("{$manifest['id']}/drill-".now('UTC')->format('Ymd\THis\Z').'.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->record($manifest['id'], $passed, [
            'tables' => $tables['checked'],
            'missing' => count($tables['missing']),
            'emptied' => count($tables['emptied']),
            'differences' => count($tables['differences']),
            'tenant_databases' => array_keys($tenantDatabases),
        ]);

        return $report;
    }

    /**
     * The drill connection: the app's own settings with the drill database's name
     * (and optionally host and user); "<drill database>_<name>" for a tenant
     * database. Refuses to point at the app's own or any tenant database.
     * Returns the connection name.
     */
    public function configureConnection(?string $tenantDatabase = null): string
    {
        $default = (string) config('database.default');
        $app = config("database.connections.{$default}");
        $drill = config('security.backups.drill');

        if (! is_string($drill['database'] ?? null) || $drill['database'] === '') {
            throw BackupException::drillNotConfigured();
        }

        $name = $tenantDatabase === null ? self::CONNECTION : self::CONNECTION.'_'.$tenantDatabase;
        $connection = array_merge($app, array_filter([
            'url' => null,
            'database' => $drill['database'].($tenantDatabase === null ? '' : '_'.$tenantDatabase),
            'host' => $drill['host'] ?? null,
            'username' => $drill['username'] ?? null,
            'password' => $drill['password'] ?? null,
        ], fn ($value) => $value !== null));
        $connection['url'] = null;

        $live = [$default, ...array_map(fn (string $tenant) => TenantDatabases::CONNECTION_PREFIX.$tenant, array_keys((array) config('tenant_databases.databases', [])))];
        foreach ($live as $liveConnection) {
            $target = (array) config("database.connections.{$liveConnection}");
            $sameHost = ($connection['host'] ?? null) === ($target['host'] ?? null) && ($connection['port'] ?? null) === ($target['port'] ?? null);

            if ($sameHost && strcasecmp((string) $connection['database'], (string) ($target['database'] ?? '')) === 0) {
                throw BackupException::drillTargetsApp();
            }
        }

        config(['database.connections.'.$name => $connection]);
        DB::purge($name);

        return $name;
    }

    /**
     * @param  array<string, int>  $expected
     * @return array<string, mixed>
     */
    private function compareTables(array $expected, string $connection): array
    {
        $restored = array_flip(BackupService::tables($connection));
        $missing = [];
        $emptied = [];
        $differences = [];

        foreach ($expected as $table => $rows) {
            if (! isset($restored[$table])) {
                $missing[] = $table;

                continue;
            }

            $count = DB::connection($connection)->table($table)->count();

            if ($rows > 0 && $count === 0) {
                $emptied[] = $table;
            } elseif ($count !== $rows) {
                $differences[$table] = ['backed_up' => $rows, 'restored' => $count];
            }
        }

        return [
            'checked' => count($expected),
            'rows' => array_sum($expected),
            'missing' => $missing,
            'emptied' => $emptied,
            'differences' => $differences,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function checkFiles(array $manifest, string $work): array
    {
        if ($manifest['files'] === null) {
            return ['passed' => true, 'expected' => 0, 'found' => 0];
        }

        $this->backups->open($manifest['id'], BackupService::FILES_FILE, $manifest['files'], "{$work}/files.tar");
        $found = iterator_count(new RecursiveIteratorIterator(new PharData("{$work}/files.tar")));

        return ['passed' => $found === $manifest['files']['count'], 'expected' => $manifest['files']['count'], 'found' => $found];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function record(string $set, bool $passed, array $details): void
    {
        $this->audit->record($passed ? 'backup.restore_drill_passed' : 'backup.restore_drill_failed', new: ['set' => $set, ...$details]);
    }
}
