<?php

use App\Platform\Security\Backups\BackupCipher;

/*
 * Phase 8-2, infrastructure: security:check stops a production deploy while
 * any setting is unsafe, and says what to change.
 */

function productionSettings(): void
{
    app()['env'] = 'production';
    $connection = config('database.default');

    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.url' => 'https://erp.example.com',
        'session.secure' => true,
        'session.encrypt' => true,
        'session.same_site' => 'lax',
        "database.connections.{$connection}.username" => 'erp_app',
        "database.connections.{$connection}.password" => 'a-long-password',
        'logging.channels.'.config('logging.default').'.level' => 'warning',
        'logging.channels.single.level' => 'warning',
        'security.backups.keys' => ['v1' => BackupCipher::generateKey()],
        'security.backups.key_version' => 'v1',
        'security.backups.disk' => 's3',
        'security.backups.drill.database' => 'erp_restore_drill',
    ]);
}

afterEach(function () {
    app()['env'] = 'testing';
});

it('passes with production settings', function () {
    productionSettings();

    $this->artisan('security:check')->expectsOutputToContain('All checks passed.')->assertSuccessful();
});

it('stops a production deploy while debug mode is on, and says what to do', function () {
    productionSettings();
    config(['app.debug' => true]);

    $this->artisan('security:check')
        ->expectsOutputToContain('Set APP_DEBUG=false')
        ->expectsOutputToContain('1 of 17 checks need attention.')
        ->assertFailed();
});

it('flags an admin database user, plain HTTP and a missing backup key', function () {
    productionSettings();
    config([
        'database.connections.'.config('database.default').'.username' => 'root',
        'app.url' => 'http://erp.example.com',
        'security.backups.keys' => [],
    ]);

    $this->artisan('security:check')
        ->expectsOutputToContain('db-least-privilege.md')
        ->expectsOutputToContain('Set APP_URL to the https:// address.')
        ->expectsOutputToContain('backup:run --generate-key')
        ->assertFailed();
});

it('only informs outside production, unless strict', function () {
    config(['app.debug' => true]);

    $this->artisan('security:check')->expectsOutputToContain('Not production')->assertSuccessful();
    $this->artisan('security:check --strict')->assertFailed();
});
