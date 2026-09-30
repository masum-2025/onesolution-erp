<?php

use App\Platform\Analytics\AnalyticsExport;
use App\Platform\Analytics\Jobs\ExportAnalytics;
use App\Platform\Analytics\ReportingDatabase;
use App\Platform\Audit\AuditLogger;
use App\Platform\Audit\AuditQuery;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Phase 10-3: heavy analytics in a separate store, fed by queued jobs, and
 * heavy reads on a reporting replica. Only ids, actions and times leave.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->owner = createMember($this->w->c1);

    // Three entries old enough to send (the export waits out a short lag).
    foreach (['rule.changed', 'module.enabled', 'billing.invoice_issued'] as $n => $action) {
        app(AuditLogger::class)->record($action, reason: 'secret reason', actor: $this->owner, organizationId: $this->w->c1->id, partnerId: $this->w->partnerA->id, new: ['amount' => 5])
            ->forceFill(['created_at' => now()->subMinutes(10 - $n), 'ip_address' => '203.0.113.9'])->saveQuietly();
    }
});

const ANALYTICS_FIELDS = ['id', 'created_at', 'partner_id', 'organization_id', 'action', 'area', 'target_type'];

it('writes JSON lines with ids, actions and times only, once each', function () {
    Storage::fake('local');
    config(['analytics.driver' => 'jsonl']);

    $sent = app(AnalyticsExport::class)->run();
    $again = app(AnalyticsExport::class)->run();

    $lines = array_map(fn ($line) => json_decode($line, true), explode("\n", Storage::disk('local')->get('analytics/audit_events/'.now('UTC')->format('Y-m-d').'.jsonl')));
    $mine = array_values(array_filter($lines, fn (array $row) => $row['organization_id'] === $this->w->c1->id && $row['action'] !== 'organization.created'));

    expect($sent['audit_events'])->toBeGreaterThanOrEqual(3)
        ->and($again['audit_events'])->toBe(0)
        ->and(array_column($mine, 'action'))->toBe(['rule.changed', 'module.enabled', 'billing.invoice_issued'])
        ->and($mine[2]['area'])->toBe('billing');

    foreach ($lines as $row) {
        expect(array_keys($row))->toBe(ANALYTICS_FIELDS);
    }
    expect(json_encode($lines))->not->toContain('secret reason')->not->toContain('203.0.113.9')->not->toContain($this->owner->id);
});

it('sends batches to ClickHouse over HTTP and keeps its place when ClickHouse refuses', function () {
    config([
        'analytics.driver' => 'clickhouse',
        'analytics.clickhouse.url' => 'https://ch.example.test:8443',
        'analytics.clickhouse.username' => 'erp',
        'analytics.clickhouse.password' => 'ch-secret',
    ]);

    // First refused, then accepted.
    Http::fake(['ch.example.test*' => Http::sequence()->push('', 500)->push('')]);
    expect(fn () => app(AnalyticsExport::class)->run())->toThrow(RuntimeException::class, 'HTTP 500');
    expect(DB::table('analytics_cursors')->count())->toBe(0);

    $sent = app(AnalyticsExport::class)->run()['audit_events'];

    Http::assertSent(function (Request $request) use ($sent) {
        return $request->body() !== '' && str_starts_with($request->url(), 'https://ch.example.test:8443')
            && str_contains(urldecode($request->url()), 'INSERT INTO onesolution.audit_events FORMAT JSONEachRow')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('erp:ch-secret'))
            && count(explode("\n", $request->body())) === $sent
            && ! str_contains($request->body(), 'secret reason');
    });
    expect(DB::table('analytics_cursors')->where(['dataset' => 'audit_events', 'driver' => 'clickhouse'])->value('exported'))->toBe($sent);
});

it('does nothing without an analytics store, and queues the export otherwise', function () {
    Queue::fake();

    $this->artisan('analytics:export')->expectsOutputToContain('Nothing to do')->assertSuccessful();
    Queue::assertNothingPushed();
    expect(app(AnalyticsExport::class)->run())->toBe([]);

    config(['analytics.driver' => 'jsonl']);
    $this->artisan('analytics:export')->expectsOutputToContain('queued')->assertSuccessful();
    Queue::assertPushed(ExportAnalytics::class);
});

it('reads reports from the reporting replica when one is configured', function () {
    $reporting = app(ReportingDatabase::class);

    expect($reporting->connection())->toBe(config('database.default'))
        ->and(app(AuditQuery::class)->forReporting($this->w->c1)->getConnection()->getName())->toBe(config('database.default'));

    config(['database.reporting' => ['host' => '10.0.0.7', 'database' => 'erp_replica']]);

    expect($reporting->connection())->toBe('reporting')
        ->and(config('database.connections.reporting'))->toMatchArray(['host' => '10.0.0.7', 'database' => 'erp_replica', 'driver' => config('database.connections.'.config('database.default').'.driver')])
        ->and(config('database.connections.reporting'))->not->toHaveKeys(['read', 'write'])
        ->and(app(AuditQuery::class)->forReporting($this->w->c1)->getConnection()->getName())->toBe('reporting')
        // The audit screen itself keeps reading the primary (people see their own changes at once).
        ->and(app(AuditQuery::class)->forOrganization($this->w->c1)->getConnection()->getName())->toBe(config('database.default'));
});

it('splits reads to replicas and writes to the primary when DB_READ_HOST is set', function () {
    $_ENV['DB_READ_HOST'] = $_SERVER['DB_READ_HOST'] = '10.0.0.8, 10.0.0.9';

    try {
        $config = require config_path('database.php');
    } finally {
        unset($_ENV['DB_READ_HOST'], $_SERVER['DB_READ_HOST']);
    }

    foreach (['mysql', 'pgsql'] as $driver) {
        expect($config['connections'][$driver]['read']['host'])->toBe(['10.0.0.8', '10.0.0.9'])
            ->and($config['connections'][$driver]['sticky'])->toBeTrue();
    }

    expect((require config_path('database.php'))['connections']['mysql'])->not->toHaveKey('read');
});
