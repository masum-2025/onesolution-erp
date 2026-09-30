<?php

namespace Tests\Support;

use App\Platform\Security\Backups\BackupService;
use App\Platform\Security\Backups\Contracts\DatabaseDumper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A database dumper for tests (Phase 8-2): the real dumpers run mysqldump or
 * pg_dump in another process, which cannot see a test's open transaction.
 * This one writes every table's columns and rows as JSON through the query
 * builder and loads them back as text columns: enough to prove the backup
 * pipeline (compression, encryption, manifest, restore drill) end to end.
 */
class JsonDumper implements DatabaseDumper
{
    public function dump(string $connection, string $path): void
    {
        $tables = [];

        foreach (BackupService::tables($connection) as $table) {
            $tables[$table] = [
                'columns' => Schema::connection($connection)->getColumnListing($table),
                'rows' => DB::connection($connection)->table($table)->get()->map(fn ($row) => (array) $row)->all(),
            ];
        }

        file_put_contents($path, json_encode($tables, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function restore(string $connection, string $path): void
    {
        $tables = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($tables as $table => $data) {
            Schema::connection($connection)->create($table, function (Blueprint $blueprint) use ($data) {
                foreach ($data['columns'] as $column) {
                    $blueprint->longText($column)->nullable();
                }
            });

            foreach (array_chunk($data['rows'], 200) as $chunk) {
                DB::connection($connection)->table($table)->insert($chunk);
            }
        }
    }
}
