<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Security\Backups\BackupCipher;
use App\Platform\Security\Backups\BackupException;
use App\Platform\Security\Backups\BackupService;
use App\Platform\Security\Backups\Contracts\DatabaseDumper;
use App\Platform\Security\Backups\RestoreDrill;
use App\Platform\Tenancy\Databases\TenantDatabases;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\TenantNote;
use Tests\Support\JsonDumper;

/*
 * Phase 8-2, recovery: encrypted, signed, write-once backup sets with
 * versioned keys, and a restore drill into a separate staging database.
 */

/**
 * A staging database for this test process (created once, outside the test's
 * transaction). The drill empties it every time.
 */
function drillDatabase(): string
{
    $config = config('database.connections.'.config('database.default'));
    $name = $config['database'].'_drill'.(ParallelTesting::token() ? '_'.ParallelTesting::token() : '');

    if ($config['driver'] === 'pgsql') {
        $pdo = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname=postgres", $config['username'], $config['password']);
        $exists = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $exists->execute([$name]);
        if ($exists->fetchColumn() === false) {
            $pdo->exec('CREATE DATABASE "'.$name.'"');
        }
    } else {
        $pdo = new PDO("mysql:host={$config['host']};port={$config['port']}", $config['username'], $config['password']);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$name.'`');
    }

    return $name;
}

beforeEach(function () {
    Storage::fake('backups');
    Storage::fake('local');
    app()->instance(DatabaseDumper::class, new JsonDumper);

    config([
        'security.backups.key_version' => 'v1',
        'security.backups.keys' => ['v1' => BackupCipher::generateKey()],
        'security.backups.drill.database' => drillDatabase(),
    ]);

    $this->w = tenancyWorld();
    $this->owner = createMember($this->w->c1);
});

function backups(): BackupService
{
    return app(BackupService::class);
}

it('writes an encrypted, signed set that reads back exactly', function () {
    Storage::disk('local')->put('brand/logo.png', 'png-bytes');
    Storage::disk('local')->put('exports/old.zip', 'temporary export');

    $manifest = backups()->run();
    $disk = Storage::disk('backups');

    expect($disk->exists("{$manifest['id']}/database.sql.gz.enc"))->toBeTrue()
        ->and($disk->exists("{$manifest['id']}/files.tar.gz.enc"))->toBeTrue()
        ->and($manifest['key_version'])->toBe('v1')
        ->and($manifest['files']['count'])->toBe(1)
        ->and($manifest['tables']['users'])->toBeGreaterThan(0)
        // Nothing readable in the encrypted file.
        ->and($disk->get("{$manifest['id']}/database.sql.gz.enc"))->not->toContain($this->owner->email)
        ->and(backups()->manifest())->toMatchArray(['id' => $manifest['id']]);

    $work = backups()->workDir('test');
    backups()->open($manifest['id'], BackupService::DATABASE_FILE, $manifest['database'], "{$work}/database.sql");
    expect(file_get_contents("{$work}/database.sql"))->toContain($this->owner->email);
    File::deleteDirectory($work);

    expect(AuditLog::query()->where('action', 'backup.created')->exists())->toBeTrue();
});

it('restores a set into the staging database and checks it', function () {
    Storage::disk('local')->put('brand/logo.png', 'png-bytes');
    $manifest = backups()->run();

    $report = app(RestoreDrill::class)->run();

    expect($report['passed'])->toBeTrue()
        ->and($report['set'])->toBe($manifest['id'])
        ->and($report['tables']['missing'])->toBe([])
        ->and($report['tables']['emptied'])->toBe([])
        ->and($report['tables']['differences'])->toBe([])
        ->and($report['files'])->toBe(['passed' => true, 'expected' => 1, 'found' => 1])
        ->and(DB::connection(RestoreDrill::CONNECTION)->table('users')->where('email', $this->owner->email)->exists())->toBeTrue()
        ->and(collect(Storage::disk('backups')->files($manifest['id']))->filter(fn ($file) => str_contains($file, '/drill-')))->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'backup.restore_drill_passed')->exists())->toBeTrue();
});

it('refuses a changed backup file and a changed manifest', function () {
    $manifest = backups()->run();
    $disk = Storage::disk('backups');
    $path = "{$manifest['id']}/database.sql.gz.enc";

    $bytes = $disk->get($path);
    $bytes[strlen($bytes) - 5] = chr(ord($bytes[strlen($bytes) - 5]) ^ 1);
    $disk->put($path, $bytes);

    expect(fn () => app(RestoreDrill::class)->run())->toThrow(BackupException::class, 'damaged');
    expect(AuditLog::query()->where('action', 'backup.restore_drill_failed')->exists())->toBeTrue();

    $second = backups()->run();
    $manifestPath = "{$second['id']}/manifest.json";
    $disk->put($manifestPath, str_replace('"users": '.$second['tables']['users'], '"users": 999', $disk->get($manifestPath)));

    expect(fn () => backups()->manifest($second['id']))->toThrow(BackupException::class, 'signature');
});

it('refuses a file that was cut short, even with a matching checksum', function () {
    $cipher = app(BackupCipher::class);
    $dir = backups()->workDir('cut');
    file_put_contents("{$dir}/plain", str_repeat('row;', 600000));
    $cipher->encrypt("{$dir}/plain", "{$dir}/enc");

    // Drop the final chunk: every remaining chunk is still authentic.
    $bytes = file_get_contents("{$dir}/enc");
    $firstChunkEnd = 5 + 1 + 2 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES + 4 + unpack('N', substr($bytes, 8 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES, 4))[1];
    file_put_contents("{$dir}/cut", substr($bytes, 0, $firstChunkEnd));

    expect(fn () => $cipher->decrypt("{$dir}/cut", "{$dir}/out", 'test'))->toThrow(BackupException::class, 'damaged');
    expect($cipher->decrypt("{$dir}/enc", "{$dir}/out", 'test'))->toBe('v1')
        ->and(file_get_contents("{$dir}/out"))->toBe(file_get_contents("{$dir}/plain"));

    File::deleteDirectory($dir);
});

it('rotates keys: new sets use the new key, older sets stay readable with theirs', function () {
    $old = backups()->run();

    config([
        'security.backups.key_version' => 'v2',
        'security.backups.keys' => [...config('security.backups.keys'), 'v2' => BackupCipher::generateKey()],
    ]);
    $new = backups()->run();

    expect($new['key_version'])->toBe('v2')
        ->and(app(RestoreDrill::class)->run($old['id'])['passed'])->toBeTrue()
        ->and(app(RestoreDrill::class)->run($new['id'])['passed'])->toBeTrue();

    // Without the old key, the old set cannot be read (and says which key is missing).
    config(['security.backups.keys' => ['v2' => config('security.backups.keys.v2')]]);
    expect(fn () => backups()->manifest($old['id']))->toThrow(BackupException::class, 'BACKUP_KEY_V1');
});

it('keeps the configured number of newest sets', function () {
    config(['security.backups.keep' => 2]);

    foreach (range(1, 3) as $day) {
        $this->travel(1)->days();
        $ids[] = backups()->run()['id'];
    }

    expect(backups()->prune())->toBe([$ids[0]])
        ->and(backups()->sets())->toBe([$ids[2], $ids[1]]);
});

it('never restores into the app\'s own database, and needs a staging database', function () {
    backups()->run();

    config(['security.backups.drill.database' => config('database.connections.'.config('database.default').'.database')]);
    expect(fn () => app(RestoreDrill::class)->run())->toThrow(BackupException::class, 'separate staging database');

    config(['security.backups.drill.database' => null]);
    expect(fn () => app(RestoreDrill::class)->run())->toThrow(BackupException::class, 'BACKUP_DRILL_DATABASE');
});

it('backs up and restores every dedicated client database on its own (Phase 10)', function () {
    dedicatedTenantDatabase();
    placeClient($this->w->g1);
    TenantNote::create(['title' => 'kept safe', 'organization_id' => $this->w->c1->id]);
    ensureTestDatabase(config('security.backups.drill.database').'_dedicated');

    $manifest = backups()->run();

    expect($manifest['tenant_databases']['dedicated']['tables']['tenant_notes'])->toBe(1)
        ->and(Storage::disk('backups')->get("{$manifest['id']}/".BackupService::tenantFile('dedicated')))->not->toContain('kept safe');

    $report = app(RestoreDrill::class)->run();

    expect($report['passed'])->toBeTrue()
        ->and($report['tenant_databases']['dedicated']['missing'])->toBe([])
        ->and($report['tenant_databases']['dedicated']['emptied'])->toBe([])
        ->and(DB::connection(RestoreDrill::CONNECTION.'_dedicated')->table('tenant_notes')->where('title', 'kept safe')->exists())->toBeTrue()
        // The main database's copy has no business rows of the dedicated client.
        ->and(DB::connection(RestoreDrill::CONNECTION)->table('tenant_notes')->count())->toBe(0);
});

it('never restores into a client database', function () {
    TenantDatabases::register('dedicated', ['database' => config('security.backups.drill.database').'_dedicated']);

    expect(fn () => app(RestoreDrill::class)->configureConnection('dedicated'))->toThrow(BackupException::class, 'separate staging database');
});

it('stops with a clear message without a key', function () {
    config(['security.backups.keys' => []]);

    $this->artisan('backup:run')->expectsOutputToContain('BACKUP_KEY_V1')->assertFailed();
    expect(Storage::disk('backups')->directories())->toBe([]);

    config(['security.backups.keys' => ['v1' => base64_encode('too short')]]);
    $this->artisan('backup:run')->expectsOutputToContain('exactly 32 bytes')->assertFailed();
});

it('runs from the console: backup, prune and drill', function () {
    config(['security.backups.keep' => 1]);

    $this->artisan('backup:run')->expectsOutputToContain('Backup set')->assertSuccessful();
    $this->artisan('backup:restore-drill')->expectsOutputToContain('The restore drill passed.')->assertSuccessful();
    $this->artisan('backup:run --generate-key')->assertSuccessful();

    expect(strlen(base64_decode(BackupCipher::generateKey())))->toBe(32);
});
