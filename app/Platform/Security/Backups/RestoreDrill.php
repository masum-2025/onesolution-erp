<?php

namespace App\Platform\Security\Backups;

use App\Platform\Audit\AuditLogger;
use App\Platform\Security\Backups\Contracts\DatabaseDumper;
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

            $tables = $this->compareTables($manifest['tables']);
            $files = $this->checkFiles($manifest, $work);
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

        $passed = $tables['missing'] === [] && $tables['emptied'] === [] && $files['passed'];

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
        ];

        $this->backups->disk()->put("{$manifest['id']}/drill-".now('UTC')->format('Ymd\THis\Z').'.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->record($manifest['id'], $passed, [
            'tables' => $tables['checked'],
            'missing' => count($tables['missing']),
            'emptied' => count($tables['emptied']),
            'differences' => count($tables['differences']),
        ]);

        return $report;
    }

    /**
     * The drill connection: the app's own settings with the drill database's name
     * (and optionally host and user). Refuses to point at the app's database.
     */
    public function configureConnection(): void
    {
        $default = (string) config('database.default');
        $app = config("database.connections.{$default}");
        $drill = config('security.backups.drill');

        if (! is_string($drill['database'] ?? null) || $drill['database'] === '') {
            throw BackupException::drillNotConfigured();
        }

        $connection = array_merge($app, array_filter([
            'url' => null,
            'database' => $drill['database'],
            'host' => $drill['host'] ?? null,
            'username' => $drill['username'] ?? null,
            'password' => $drill['password'] ?? null,
        ], fn ($value) => $value !== null));
        $connection['url'] = null;

        $sameHost = ($connection['host'] ?? null) === ($app['host'] ?? null) && ($connection['port'] ?? null) === ($app['port'] ?? null);
        if ($sameHost && strcasecmp((string) $connection['database'], (string) $app['database']) === 0) {
            throw BackupException::drillTargetsApp();
        }

        config(['database.connections.'.self::CONNECTION => $connection]);
        DB::purge(self::CONNECTION);
    }

    /**
     * @param  array<string, int>  $expected
     * @return array<string, mixed>
     */
    private function compareTables(array $expected): array
    {
        $restored = array_flip(BackupService::tables(self::CONNECTION));
        $missing = [];
        $emptied = [];
        $differences = [];

        foreach ($expected as $table => $rows) {
            if (! isset($restored[$table])) {
                $missing[] = $table;

                continue;
            }

            $count = DB::connection(self::CONNECTION)->table($table)->count();

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
