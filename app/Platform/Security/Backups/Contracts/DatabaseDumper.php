<?php

namespace App\Platform\Security\Backups\Contracts;

/**
 * Writes a database connection to a plain SQL file and loads one back. The
 * only vendor-specific part of backups: one implementation per database
 * (MySQL, PostgreSQL), using the database's own tools.
 */
interface DatabaseDumper
{
    /** Dump the named connection's database into $path. */
    public function dump(string $connection, string $path): void;

    /** Load the dump at $path into the named connection's (empty) database. */
    public function restore(string $connection, string $path): void;
}
