<?php

use App\Platform\Audit\AuditLogger;
use App\Platform\Monitoring\HealthReport;
use App\Platform\Monitoring\Metrics;
use App\Platform\Monitoring\Models\SecurityAlert;
use App\Platform\Tenancy\Databases\PlacementStatus;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Databases\TenantPlacement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Phase 9-2: the health report for monitoring tools: queue, failed jobs,
 * sync errors, rule cache, backups, restore drill, scheduler, open alerts.
 * Counts and times only; behind a bearer token.
 */

beforeEach(function () {
    config(['monitoring.health.token' => 'health-secret-token']);
});

function healthUrl(): string
{
    $url = parse_url(config('app.url'));

    return 'http://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '').'/internal/health';
}

function recordPlatform(string $action, string $when = 'now'): void
{
    $entry = app(AuditLogger::class)->record($action);
    $entry->forceFill(['created_at' => now()->modify($when)])->saveQuietly();
}

it('does not exist without the right token', function () {
    $this->getJson(healthUrl())->assertNotFound();
    $this->withToken('wrong')->getJson(healthUrl())->assertNotFound();

    config(['monitoring.health.token' => null]);
    $this->withToken('')->getJson(healthUrl())->assertNotFound();
});

it('reports every check, with counts and times only', function () {
    $w = tenancyWorld();
    $this->artisan('monitor:heartbeat')->assertSuccessful();
    recordPlatform('backup.created', '-2 hours');
    recordPlatform('backup.restore_drill_passed', '-3 days');

    $response = $this->withToken('health-secret-token')->getJson(healthUrl())->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    expect(array_keys($response->json('checks')))->toBe([
        'database', 'tenant_databases', 'cache', 'queue_depth', 'failed_jobs', 'sync_errors', 'rule_cache_hit_rate', 'backup', 'restore_drill', 'scheduler', 'open_alerts',
    ])
        ->and($response->json('status'))->toBe('ok')
        ->and($response->json('checks.backup.status'))->toBe('ok')
        ->and($response->json('checks.scheduler.status'))->toBe('ok')
        ->and($response->getContent())->not->toContain($w->c1->displayName());
});

it('fails, with 503, when the last restore drill failed or the backup is old', function () {
    recordPlatform('backup.created', '-3 days');
    recordPlatform('backup.restore_drill_failed', '-1 hour');

    $response = $this->withToken('health-secret-token')->getJson(healthUrl())->assertStatus(503);

    expect($response->json('status'))->toBe('fail')
        ->and($response->json('checks.backup.status'))->toBe('fail')
        ->and($response->json('checks.restore_drill.status'))->toBe('fail');

    $this->artisan('health:report')->expectsOutputToContain('Overall: FAIL')->assertFailed();
});

it('notices a silent scheduler, failed jobs and open high alerts', function () {
    $this->artisan('monitor:heartbeat');
    $this->travel(10)->minutes();
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
    SecurityAlert::create(['kind' => 'brute_force_address', 'severity' => 'high', 'fingerprint' => sha1('x'), 'count' => 30, 'first_seen_at' => now(), 'last_seen_at' => now()]);

    $report = app(HealthReport::class)->run()['checks'];

    expect($report['scheduler']['status'])->toBe('fail')
        ->and($report['failed_jobs'])->toMatchArray(['status' => 'warn', 'value' => 1])
        ->and($report['open_alerts'])->toMatchArray(['status' => 'warn', 'value' => ['low' => 0, 'medium' => 0, 'high' => 1]]);
});

it('checks every client database and notices a move that does not finish (Phase 10)', function () {
    $w = tenancyWorld();
    dedicatedTenantDatabase();
    placeClient($w->g1);

    expect(app(HealthReport::class)->run()['checks']['tenant_databases'])
        ->toMatchArray(['status' => 'ok', 'value' => ['configured' => 1, 'unavailable' => [], 'stuck_moves' => 0]]);

    TenantPlacement::query()->update(['status' => PlacementStatus::Moving->value, 'status_changed_at' => now()->subHours(3)]);
    expect(app(HealthReport::class)->run()['checks']['tenant_databases']['status'])->toBe('warn');

    // A database without its tables (never migrated) is a failure, named but without details.
    TenantDatabases::register('empty', ['database' => config('database.connections.'.config('database.default').'.database').'_empty']);
    ensureTestDatabase(config('database.connections.tenant_empty.database'));

    $check = app(HealthReport::class)->run()['checks']['tenant_databases'];
    expect($check['status'])->toBe('fail')
        ->and($check['value']['unavailable'])->toBe(['empty' => 'RuntimeException']);
});

it('measures the rule cache hit rate once there are enough lookups', function () {
    $metrics = app(Metrics::class);
    $metrics->count('rule_cache.hit', 90);
    $metrics->count('rule_cache.miss', 10);

    expect(app(HealthReport::class)->run()['checks']['rule_cache_hit_rate'])->toMatchArray(['status' => 'ok', 'value' => 90]);

    $metrics->count('rule_cache.miss', 200);
    expect(app(HealthReport::class)->run()['checks']['rule_cache_hit_rate']['status'])->toBe('warn');
});
