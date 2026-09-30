<?php

namespace App\Platform\Security\Backups;

use App\Platform\Security\Backups\Contracts\DatabaseDumper;
use App\Platform\Security\Backups\Dumpers\MysqlDumper;
use App\Platform\Security\Backups\Dumpers\PostgresDumper;

class DumperFactory
{
    public function forDriver(string $driver): DatabaseDumper
    {
        return match ($driver) {
            'mysql', 'mariadb' => new MysqlDumper,
            'pgsql' => new PostgresDumper,
            default => throw BackupException::unsupportedDriver($driver),
        };
    }
}
