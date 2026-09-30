<?php

namespace App\Platform\Security\Backups;

use App\Platform\Audit\AuditLogger;
use App\Platform\Security\Backups\Contracts\DatabaseDumper;
use FilesystemIterator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Encrypted backup sets (Phase 8-2). One set = one folder on the backup disk:
 *
 *   {id}/database.sql.gz.enc   the database (the driver's own dump tool)
 *   {id}/files.tar.gz.enc      the app's private files (brand images, …)
 *   {id}/manifest.json         checksums, table row counts, key version, signed
 *
 * Sets are written once and never overwritten; old sets are removed by age.
 * With an off-site disk that has object lock, they cannot be changed at all.
 */
class BackupService
{
    public const DATABASE_FILE = 'database.sql.gz.enc';

    public const FILES_FILE = 'files.tar.gz.enc';

    public const MANIFEST_FILE = 'manifest.json';

    public function __construct(
        private DatabaseDumper $dumper,
        private BackupCipher $cipher,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed> The manifest of the new set.
     */
    public function run(): array
    {
        $connection = (string) config('database.default');
        $id = now('UTC')->format('Ymd\THis\Z').'-'.Str::lower(Str::random(6));
        $disk = $this->disk();

        if ($disk->exists("{$id}/".self::MANIFEST_FILE)) {
            throw BackupException::exists($id);
        }

        $work = $this->workDir($id);

        try {
            // Row counts first: the dump is a consistent snapshot taken right after,
            // so on a busy database a few rows may differ (the drill reports them).
            $tables = $this->rowCounts($connection);

            $this->dumper->dump($connection, "{$work}/database.sql");
            $database = $this->seal("{$work}/database.sql", $disk, "{$id}/".self::DATABASE_FILE, $work);

            $files = $this->archiveFiles("{$work}/files.tar");
            $filesEntry = $files === null ? null : [
                ...$this->seal("{$work}/files.tar", $disk, "{$id}/".self::FILES_FILE, $work),
                'count' => $files,
            ];

            $manifest = [
                'format' => 1,
                'id' => $id,
                'created_at' => now('UTC')->toIso8601String(),
                'app_env' => (string) config('app.env'),
                'driver' => (string) config("database.connections.{$connection}.driver"),
                'key_version' => $this->cipher->currentVersion(),
                'database' => $database,
                'files' => $filesEntry,
                'tables' => $tables,
            ];
            $manifest['signature'] = $this->cipher->sign($this->canonical($manifest), $manifest['key_version']);

            $disk->put("{$id}/".self::MANIFEST_FILE, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $exception) {
            // A half-written set is never left behind to be mistaken for a good one.
            rescue(fn () => $disk->deleteDirectory($id), report: false);

            throw $exception;
        } finally {
            File::deleteDirectory($work);
        }

        $this->audit->record('backup.created', new: [
            'set' => $id,
            'key_version' => $manifest['key_version'],
            'tables' => count($tables),
            'database_bytes' => $database['bytes'],
            'files' => $files ?? 0,
        ]);

        return $manifest;
    }

    /**
     * Sets on the backup disk, newest first.
     *
     * @return list<string>
     */
    public function sets(): array
    {
        $sets = array_values(array_filter(
            $this->disk()->directories(),
            fn (string $directory) => preg_match('/^\d{8}T\d{6}Z-[a-z0-9]{6}$/', $directory) === 1,
        ));
        rsort($sets);

        return $sets;
    }

    /**
     * Removes the oldest sets beyond the number to keep.
     *
     * @return list<string> The removed sets.
     */
    public function prune(): array
    {
        $keep = max(1, (int) config('security.backups.keep'));
        $removed = array_slice($this->sets(), $keep);

        foreach ($removed as $set) {
            $this->disk()->deleteDirectory($set);
        }

        if ($removed !== []) {
            $this->audit->record('backup.pruned', new: ['sets' => $removed]);
        }

        return $removed;
    }

    /**
     * The manifest of a set (the newest by default), after checking its signature.
     *
     * @return array<string, mixed>
     */
    public function manifest(?string $id = null): array
    {
        $id ??= $this->sets()[0] ?? null;
        $path = "{$id}/".self::MANIFEST_FILE;

        if ($id === null || ! in_array($id, $this->sets(), true) || ! $this->disk()->exists($path)) {
            throw BackupException::notFound($id);
        }

        $manifest = json_decode((string) $this->disk()->get($path), true);
        $signature = is_array($manifest) ? ($manifest['signature'] ?? null) : null;
        unset($manifest['signature']);

        if (! is_string($signature) || ! is_string($manifest['key_version'] ?? null)
            || ! $this->cipher->verify($this->canonical($manifest), $signature, $manifest['key_version'])) {
            throw BackupException::manifestTampered($id);
        }

        return $manifest;
    }

    /**
     * Decrypts one file of a set into $plainPath and checks it against the manifest.
     *
     * @param  array<string, mixed>  $entry  The manifest's entry for the file.
     */
    public function open(string $id, string $file, array $entry, string $plainPath): void
    {
        $work = dirname($plainPath);
        $encrypted = "{$work}/".basename($file);

        $stream = $this->disk()->readStream("{$id}/{$file}");
        $target = fopen($encrypted, 'wb');
        stream_copy_to_stream($stream, $target);
        fclose($target);
        is_resource($stream) && fclose($stream);

        if (hash_file('sha256', $encrypted) !== ($entry['encrypted_sha256'] ?? null)) {
            throw BackupException::unreadable($file);
        }

        $this->cipher->decrypt($encrypted, "{$plainPath}.gz", $file);
        $this->gunzip("{$plainPath}.gz", $plainPath);
        @unlink($encrypted);
        @unlink("{$plainPath}.gz");

        if (hash_file('sha256', $plainPath) !== ($entry['sha256'] ?? null)) {
            throw BackupException::checksumMismatch($file);
        }
    }

    /**
     * The tables of the connection's own database (or schemas on its search path),
     * never other databases on the same server.
     *
     * @return list<string>
     */
    public static function tables(string $connection): array
    {
        $schema = Schema::connection($connection);

        return array_values($schema->getTableListing($schema->getCurrentSchemaListing(), schemaQualified: false));
    }

    public function workDir(string $name): string
    {
        $dir = storage_path('framework/backup-work/'.$name.'-'.Str::lower(Str::random(8)));
        File::ensureDirectoryExists($dir, 0700);

        return $dir;
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('security.backups.disk'));
    }

    /**
     * Compress, encrypt and upload one file; returns its manifest entry.
     *
     * @return array<string, mixed>
     */
    private function seal(string $plainPath, Filesystem $disk, string $target, string $work): array
    {
        $entry = ['sha256' => hash_file('sha256', $plainPath), 'bytes' => filesize($plainPath)];

        $this->gzip($plainPath, "{$plainPath}.gz");
        $this->cipher->encrypt("{$plainPath}.gz", "{$plainPath}.gz.enc");
        @unlink($plainPath);
        @unlink("{$plainPath}.gz");

        $entry['encrypted_sha256'] = hash_file('sha256', "{$plainPath}.gz.enc");

        $stream = fopen("{$plainPath}.gz.enc", 'rb');
        try {
            $disk->writeStream($target, $stream);
        } finally {
            fclose($stream);
        }

        return $entry;
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(string $connection): array
    {
        $counts = [];

        foreach (self::tables($connection) as $table) {
            $counts[$table] = DB::connection($connection)->table($table)->count();
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Tar of the private file disk without excluded folders; null when there are no files.
     */
    private function archiveFiles(string $tarPath): ?int
    {
        $root = realpath(Storage::disk((string) config('security.backups.files.disk'))->path(''));
        if ($root === false || ! is_dir($root)) {
            return null;
        }

        $exclude = array_map(fn (string $folder) => trim($folder, '/\\'), config('security.backups.files.exclude', []));
        $paths = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (in_array(Str::before($relative, '/'), $exclude, true) || str_starts_with(basename($relative), '.git')) {
                continue;
            }

            $paths[$relative] = $file->getPathname();
        }

        if ($paths === []) {
            return null;
        }

        ksort($paths);
        $tar = new PharData($tarPath);
        foreach ($paths as $relative => $absolute) {
            $tar->addFile($absolute, $relative);
        }
        unset($tar);

        return count($paths);
    }

    private function gzip(string $from, string $to): void
    {
        $in = fopen($from, 'rb');
        $out = gzopen($to, 'wb6');

        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1048576));
        }

        fclose($in);
        gzclose($out);
    }

    private function gunzip(string $from, string $to): void
    {
        $in = gzopen($from, 'rb');
        $out = fopen($to, 'wb');

        while (! gzeof($in)) {
            fwrite($out, (string) gzread($in, 1048576));
        }

        gzclose($in);
        fclose($out);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function canonical(array $manifest): string
    {
        unset($manifest['signature']);

        return json_encode($manifest, JSON_UNESCAPED_SLASHES);
    }
}
