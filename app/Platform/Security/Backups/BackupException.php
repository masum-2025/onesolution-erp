<?php

namespace App\Platform\Security\Backups;

use RuntimeException;

/**
 * A backup or restore problem, with a message that says what to fix. Never
 * carries passwords, keys or database output that could contain data.
 */
class BackupException extends RuntimeException
{
    public static function noKey(string $version): self
    {
        return new self("No backup key for version \"{$version}\". Set BACKUP_KEY_".strtoupper($version).' to base64 of 32 random bytes (php artisan backup:run --generate-key prints one).');
    }

    public static function badKey(string $version): self
    {
        return new self("The backup key for version \"{$version}\" is not base64 of exactly 32 bytes.");
    }

    public static function unreadable(string $what): self
    {
        return new self("The backup file {$what} is damaged, was changed, or was not written by this platform.");
    }

    public static function toolFailed(string $tool, int $exitCode): self
    {
        return new self("{$tool} failed (exit code {$exitCode}). Check that it is installed (or set its path in config/security.php) and that the database user may read every table.");
    }

    public static function unsupportedDriver(string $driver): self
    {
        return new self("Backups support MySQL and PostgreSQL, not \"{$driver}\".");
    }

    public static function exists(string $id): self
    {
        return new self("A backup set named {$id} already exists; sets are never overwritten.");
    }

    public static function notFound(?string $id): self
    {
        return $id === null
            ? new self('There is no backup set yet. Run php artisan backup:run first.')
            : new self("There is no backup set named {$id}.");
    }

    public static function manifestTampered(string $id): self
    {
        return new self("The manifest of backup set {$id} does not match its signature; do not trust this set.");
    }

    public static function checksumMismatch(string $file): self
    {
        return new self("The restored {$file} does not match the checksum taken at backup time.");
    }

    public static function drillNotConfigured(): self
    {
        return new self('Set BACKUP_DRILL_DATABASE to an empty staging database (it is wiped on every drill).');
    }

    public static function drillTargetsApp(): self
    {
        return new self("The drill database is the app's own database. Point BACKUP_DRILL_DATABASE at a separate staging database; the drill wipes it.");
    }
}
