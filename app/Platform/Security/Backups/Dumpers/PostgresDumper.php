<?php

namespace App\Platform\Security\Backups\Dumpers;

use App\Platform\Security\Backups\BackupException;
use App\Platform\Security\Backups\Contracts\DatabaseDumper;
use Illuminate\Support\Facades\Process;

/**
 * PostgreSQL with pg_dump and psql (plain SQL, no owners or grants, so it
 * restores under any user). The password goes through the environment.
 */
class PostgresDumper implements DatabaseDumper
{
    public function dump(string $connection, string $path): void
    {
        $config = config("database.connections.{$connection}");

        $result = Process::env(['PGPASSWORD' => (string) ($config['password'] ?? '')])
            ->timeout((int) config('security.backups.timeout_seconds'))
            ->run([
                config('security.backups.binaries.pg_dump'),
                ...$this->connectionArguments($config),
                '--format=plain', '--no-owner', '--no-privileges', '--encoding=UTF8',
                '--file='.$path,
                (string) $config['database'],
            ]);

        if ($result->failed()) {
            throw BackupException::toolFailed('pg_dump', (int) $result->exitCode());
        }
    }

    public function restore(string $connection, string $path): void
    {
        $config = config("database.connections.{$connection}");

        $result = Process::env(['PGPASSWORD' => (string) ($config['password'] ?? '')])
            ->timeout((int) config('security.backups.timeout_seconds'))
            ->run([
                config('security.backups.binaries.psql'),
                ...$this->connectionArguments($config),
                '--quiet', '--set=ON_ERROR_STOP=1',
                '--file='.$path,
                '--dbname='.$config['database'],
            ]);

        if ($result->failed()) {
            throw BackupException::toolFailed('psql', (int) $result->exitCode());
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function connectionArguments(array $config): array
    {
        return [
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 5432),
            '--username='.($config['username'] ?? ''),
            '--no-password',
        ];
    }
}
