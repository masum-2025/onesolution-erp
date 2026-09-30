<?php

namespace App\Platform\Security\Backups\Dumpers;

use App\Platform\Security\Backups\BackupException;
use App\Platform\Security\Backups\Contracts\DatabaseDumper;
use Illuminate\Support\Facades\Process;

/**
 * MySQL / MariaDB with mysqldump and mysql. A consistent snapshot without
 * locking (--single-transaction); the password goes through the environment,
 * never the command line (other users of the server could read it there).
 */
class MysqlDumper implements DatabaseDumper
{
    public function dump(string $connection, string $path): void
    {
        $config = config("database.connections.{$connection}");

        $result = Process::env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])
            ->timeout((int) config('security.backups.timeout_seconds'))
            ->run([
                config('security.backups.binaries.mysqldump'),
                ...$this->connectionArguments($config),
                '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces',
                '--routines', '--triggers', '--hex-blob', '--default-character-set=utf8mb4',
                '--result-file='.$path,
                (string) $config['database'],
            ]);

        if ($result->failed()) {
            throw BackupException::toolFailed('mysqldump', (int) $result->exitCode());
        }
    }

    public function restore(string $connection, string $path): void
    {
        $config = config("database.connections.{$connection}");
        $input = fopen($path, 'rb');

        try {
            $result = Process::env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])
                ->timeout((int) config('security.backups.timeout_seconds'))
                ->input($input)
                ->run([
                    config('security.backups.binaries.mysql'),
                    ...$this->connectionArguments($config),
                    '--default-character-set=utf8mb4',
                    (string) $config['database'],
                ]);
        } finally {
            fclose($input);
        }

        if ($result->failed()) {
            throw BackupException::toolFailed('mysql', (int) $result->exitCode());
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function connectionArguments(array $config): array
    {
        return array_values(array_filter([
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? ''),
            ! empty($config['unix_socket']) ? '--socket='.$config['unix_socket'] : null,
        ]));
    }
}
