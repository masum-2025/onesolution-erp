<?php

namespace App\Platform\Security\Checks;

use App\Platform\Security\Backups\BackupCipher;
use App\Platform\Security\Backups\BackupException;

/**
 * Settings a production server must have (Phase 8-2). Each check says what
 * to change when it fails. Values are read from config, never printed.
 */
class ProductionReadiness
{
    /** Database users that can do anything; the app must use its own limited user. */
    private const ADMIN_DB_USERS = ['root', 'postgres', 'sa', 'admin'];

    /**
     * @return list<array{key: string, passed: bool, fix: string}>
     */
    public function run(): array
    {
        $connection = config('database.connections.'.config('database.default'));
        $usesRedis = in_array('redis', [config('cache.default'), config('queue.default'), config('session.driver')], true);

        return [
            $this->check('app_env', config('app.env') === 'production', 'Set APP_ENV=production.'),
            $this->check('app_debug_off', config('app.debug') === false, 'Set APP_DEBUG=false: error pages must never show code, queries or settings.'),
            $this->check('app_key', is_string(config('app.key')) && strlen((string) config('app.key')) >= 32, 'Set APP_KEY (php artisan key:generate). Keep old keys in APP_PREVIOUS_KEYS when rotating.'),
            $this->check('https_url', str_starts_with((string) config('app.url'), 'https://'), 'Set APP_URL to the https:// address.'),
            $this->check('hsts', (bool) config('security.hsts.enabled') && (int) config('security.hsts.max_age') >= 15552000, 'Keep SECURITY_HSTS on with SECURITY_HSTS_MAX_AGE of at least 15552000 (180 days).'),
            $this->check('session_secure_cookie', config('session.secure') === true, 'Set SESSION_SECURE_COOKIE=true (cookie over HTTPS only).'),
            $this->check('session_encrypted', config('session.encrypt') === true, 'Set SESSION_ENCRYPT=true.'),
            $this->check('session_http_only', config('session.http_only') !== false, 'Keep the session cookie HttpOnly (config/session.php http_only).'),
            $this->check('session_same_site', in_array(config('session.same_site'), ['lax', 'strict'], true), 'Set SESSION_SAME_SITE to lax or strict.'),
            $this->check('db_not_admin_user', ! in_array(strtolower((string) ($connection['username'] ?? '')), self::ADMIN_DB_USERS, true), 'Use a dedicated database user with only the rights in docs/ops/db-least-privilege.md, not an admin user.'),
            $this->check('db_password', ($connection['password'] ?? '') !== '', 'Give the database user a password (DB_PASSWORD).'),
            $this->check('redis_password', ! $usesRedis || (string) config('database.redis.default.password') !== '', 'Set REDIS_PASSWORD, and keep Redis on the private network.'),
            $this->check('env_not_public', ! file_exists(public_path('.env')) && ! file_exists(public_path('.env.example')), 'Remove .env files from public/: the web root must hold only public assets.'),
            $this->check('log_level', config('logging.channels.'.config('logging.default').'.level') !== 'debug' && config('logging.channels.single.level') !== 'debug', 'Set LOG_LEVEL=warning (or info): debug logs can carry request details.'),
            $this->check('backup_key', $this->backupKeyUsable(), 'Set BACKUP_KEY_'.strtoupper((string) config('security.backups.key_version')).' (php artisan backup:run --generate-key) and keep a copy offline: without it no backup can be read.'),
            $this->check('backup_offsite', config('security.backups.disk') !== 'backups', 'Point BACKUP_DISK at an off-site disk with object lock; the local "backups" disk is on the same server.'),
            $this->check('restore_drill', (string) config('security.backups.drill.database') !== '', 'Set BACKUP_DRILL_DATABASE to a separate staging database for the monthly restore drill.'),
        ];
    }

    private function backupKeyUsable(): bool
    {
        $cipher = app(BackupCipher::class);

        try {
            $cipher->sign('check', $cipher->currentVersion());

            return true;
        } catch (BackupException) {
            return false;
        }
    }

    /**
     * @return array{key: string, passed: bool, fix: string}
     */
    private function check(string $key, bool $passed, string $fix): array
    {
        return ['key' => $key, 'passed' => $passed, 'fix' => $fix];
    }
}
