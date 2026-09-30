<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Databases\PlacementStatus;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Databases\TenantMove;
use App\Platform\Tenancy\Databases\TenantMover;
use App\Platform\Tenancy\Databases\TenantPlacement;
use App\Platform\Tenancy\Databases\TenantPlacements;
use App\Platform\Tenancy\Databases\TenantTables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\TenantNote;

/*
 * Phase 10-3: moving a client tree's business data between databases with
 * zero data loss: copy, verify every table (rows + checksum), then switch;
 * the old copy stays until purged after the retention time.
 */

beforeEach(function () {
    config(['tenant_databases.move.settle_seconds' => 0, 'tenant_databases.move.retain_days' => 30]);
    $this->w = tenancyWorld();
    $this->dedicated = dedicatedTenantDatabase();
    $this->main = config('database.default');

    foreach (['g1' => 2, 'c1' => 3, 'b1' => 1, 'c2' => 2, 'c3' => 2, 'c4' => 1] as $unit => $count) {
        foreach (range(1, $count) as $n) {
            TenantNote::create(['title' => "{$unit}-{$n}", 'organization_id' => $this->w->{$unit}->id]);
        }
    }
});

function mover(): TenantMover
{
    return app(TenantMover::class);
}

function notesIn(string $connection, array $organizations): array
{
    return DB::connection($connection)->table('tenant_notes')->whereIn('organization_id', $organizations)->orderBy('title')->pluck('title')->all();
}

it('moves a group to its own database with no data loss and a verification report', function () {
    $g1Tree = [$this->w->g1->id, $this->w->c1->id, $this->w->b1->id, $this->w->d1->id, $this->w->c2->id, $this->w->b2->id];
    $before = notesIn($this->main, $g1Tree);

    $move = mover()->move($this->w->g1->fresh(), 'dedicated', 'Large client');

    expect($move->status)->toBe(TenantMove::COMPLETED)
        ->and($move->report['verified'])->toBeTrue()
        ->and($move->report['rows'])->toBe(8)
        ->and($move->report['tables']['tenant_notes'])->toMatchArray(['source_rows' => 8, 'target_rows' => 8, 'match' => true])
        ->and(notesIn($this->dedicated, $g1Tree))->toBe($before)
        // Other clients never enter the new database.
        ->and(notesIn($this->dedicated, [$this->w->c3->id, $this->w->c4->id]))->toBe([])
        ->and(app(TenantDatabases::class)->forRoot($this->w->g1->id))->toBe('tenant_dedicated');

    expect(TenantPlacement::query()->sole())
        ->database->toBe('dedicated')
        ->status->toBe(PlacementStatus::Active)
        ->previous_database->toBeNull()
        ->and(TenantPlacement::query()->sole()->previous_retained_until->isFuture())->toBeTrue()
        // The old copy stays in the main database until purged.
        ->and(notesIn($this->main, $g1Tree))->toBe($before);

    expect(AuditLog::query()->where('action', 'tenant_database.move_started')->sole()->organization_id)->toBe($this->w->g1->id)
        ->and(AuditLog::query()->where('action', 'tenant_database.moved')->sole()->new_values)->toMatchArray(['to' => 'dedicated', 'rows' => 8]);
});

it('changes nothing when the copy does not verify', function () {
    // A copy that silently loses one row.
    app()->bind(TenantMover::class, fn ($app) => new class($app->make(TenantDatabases::class), $app->make(TenantPlacements::class), $app->make(TenantTables::class), $app->make(AuditLogger::class)) extends TenantMover
    {
        protected function copyTable(string $table, string $source, string $target, array $ids): void
        {
            parent::copyTable($table, $source, $target, $ids);
            DB::connection($target)->table($table)->whereIn('organization_id', $ids)->limit(1)->delete();
        }
    });

    $move = mover()->move($this->w->g1->fresh(), 'dedicated', 'Large client');

    expect($move->status)->toBe(TenantMove::FAILED)
        ->and($move->report['tables']['tenant_notes']['match'])->toBeFalse()
        ->and(DB::connection($this->dedicated)->table('tenant_notes')->count())->toBe(0)
        ->and(app(TenantDatabases::class)->forRoot($this->w->g1->id))->toBe($this->main)
        ->and(TenantPlacement::query()->sole()->status)->toBe(PlacementStatus::Active)
        ->and(AuditLog::query()->where('action', 'tenant_database.move_failed')->sole()->new_values['tables_not_matching'])->toBe(['tenant_notes']);
});

it('refuses a target that already holds rows of this client', function () {
    DB::connection($this->dedicated)->table('tenant_notes')->insert(['id' => (string) Str::ulid(), 'organization_id' => $this->w->c1->id, 'title' => 'x']);

    expect(fn () => mover()->move($this->w->g1->fresh(), 'dedicated', 'Large client'))->toThrow(RuntimeException::class, 'already holds');
    expect(TenantMove::query()->count())->toBe(0);
});

it('refuses a unit below the top, the same database, and a second move at once', function () {
    expect(fn () => mover()->move($this->w->c1->fresh(), 'dedicated', 'x x x'))->toThrow(RuntimeException::class, 'top organization')
        ->and(fn () => mover()->move($this->w->g1->fresh(), null, 'x x x'))->toThrow(RuntimeException::class, 'already uses');

    TenantPlacement::create(['root_organization_id' => $this->w->g1->id, 'strategy' => 'shared', 'database' => null, 'status' => PlacementStatus::Moving]);
    expect(fn () => mover()->move($this->w->g1->fresh(), 'dedicated', 'x x x'))->toThrow(RuntimeException::class, 'under way');
});

it('moves back, replacing the stale old copy with the current data', function () {
    mover()->move($this->w->g1->fresh(), 'dedicated', 'Large client');
    DB::connection($this->dedicated)->table('tenant_notes')->where('title', 'c1-1')->update(['title' => 'c1-1 changed after the move']);

    $back = mover()->move($this->w->g1->fresh(), null, 'Back to shared');

    expect($back->status)->toBe(TenantMove::COMPLETED)
        ->and($back->report['stale_rows_removed'])->toBe(8)
        ->and(notesIn($this->main, [$this->w->c1->id]))->toContain('c1-1 changed after the move')
        ->and(notesIn($this->main, [$this->w->c1->id]))->not->toContain('c1-1')
        ->and(app(TenantDatabases::class)->forRoot($this->w->g1->id))->toBe($this->main)
        ->and(TenantPlacement::query()->sole()->previous_database)->toBe('dedicated');
});

it('removes the old copy only after the retention time, with typed confirmation', function () {
    mover()->move($this->w->g1->fresh(), 'dedicated', 'Large client');
    $g1Tree = [$this->w->g1->id, $this->w->c1->id, $this->w->b1->id, $this->w->c2->id];

    $this->artisan('tenants:purge-source', ['organization' => $this->w->g1->id, '--confirm' => 'wrong', '--reason' => 'Space'])->assertExitCode(2);
    $this->artisan('tenants:purge-source', ['organization' => $this->w->g1->id, '--confirm' => $this->w->g1->id, '--reason' => 'Space'])
        ->expectsOutputToContain('kept until')->assertFailed();

    $this->travel(31)->days();
    $this->artisan('tenants:purge-source', ['organization' => $this->w->g1->id, '--confirm' => $this->w->g1->id, '--reason' => 'Space'])
        ->expectsOutputToContain('Removed 8 rows')->assertSuccessful();

    expect(notesIn($this->main, $g1Tree))->toBe([])
        ->and(notesIn($this->dedicated, $g1Tree))->toHaveCount(8)
        // Other clients' rows in the main database are untouched.
        ->and(notesIn($this->main, [$this->w->c3->id, $this->w->c4->id]))->toHaveCount(3)
        ->and(TenantPlacement::query()->sole()->previous_retained_until)->toBeNull()
        ->and(AuditLog::query()->where('action', 'tenant_database.source_purged')->sole()->new_values)->toMatchArray(['database' => null, 'rows' => 8]);
});

it('moves from the command line with a reason and prints the report', function () {
    $this->artisan('tenants:move', ['organization' => $this->w->g1->id, 'database' => 'dedicated', '--force' => true])
        ->expectsOutputToContain('--reason')->assertExitCode(2);

    $this->artisan('tenants:move', ['organization' => $this->w->g1->id, 'database' => 'dedicated', '--reason' => 'Large client', '--force' => true])
        ->expectsOutputToContain('Moved 8 rows')
        ->assertSuccessful();

    $this->artisan('tenants:list')->expectsOutputToContain('dedicated')->assertSuccessful();
});
