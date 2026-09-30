<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Tenancy\Databases\DatabaseStrategy;
use App\Platform\Tenancy\Databases\PlacementStatus;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Databases\TenantPlacement;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Exceptions\HierarchyViolation;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use App\Platform\Tenancy\Services\HierarchyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\TenantNote;

/*
 * Phase 10-2: a client tree's business data lives in the main database, or in
 * a dedicated / regional one. Platform data always stays in the main one.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
});

function tenantNoteOrganizations(string $connection): array
{
    return DB::connection($connection)->table('tenant_notes')->orderBy('title')->pluck('organization_id')->all();
}

it('writes a dedicated client\'s business data to its own database only', function () {
    $dedicated = dedicatedTenantDatabase();
    placeClient($this->w->g1);

    TenantNote::create(['title' => 'a', 'organization_id' => $this->w->c1->id]);
    TenantNote::create(['title' => 'b', 'organization_id' => $this->w->c3->id]);

    actInOrganization(createMember($this->w->b1), $this->w->b1);
    $note = TenantNote::create(['title' => 'c']);
    $note->update(['title' => 'd']);

    expect(tenantNoteOrganizations($dedicated))->toBe([$this->w->c1->id, $this->w->b1->id])
        ->and(tenantNoteOrganizations(config('database.default')))->toBe([$this->w->c3->id])
        ->and(DB::connection($dedicated)->table('tenant_notes')->where('title', 'd')->exists())->toBeTrue();
});

it('keeps platform data in the main database', function () {
    $dedicated = dedicatedTenantDatabase();
    placeClient($this->w->g1);

    expect(DB::connection($dedicated)->getSchemaBuilder()->hasTable('organizations'))->toBeFalse()
        ->and(DB::table('organizations')->where('id', $this->w->g1->id)->exists())->toBeTrue();
});

it('lets system code read one client\'s database without a context', function () {
    dedicatedTenantDatabase();
    placeClient($this->w->g1);
    TenantNote::create(['title' => 'a', 'organization_id' => $this->w->c2->id]);

    expect(TenantNote::inTenantOf($this->w->c2)->withoutGlobalScope(OrganizationScope::class)->pluck('title')->all())->toBe(['a'])
        ->and(app(TenantDatabases::class)->within($this->w->c3, fn () => TenantNote::withoutGlobalScope(OrganizationScope::class)->count()))->toBe(0);
});

it('refuses to place a client that already has business data', function () {
    dedicatedTenantDatabase();
    TenantNote::create(['title' => 'a', 'organization_id' => $this->w->c1->id]);

    placeClient($this->w->g1);
})->throws(RuntimeException::class, 'tenants:move');

it('refuses a placement below the top of the client tree', function () {
    dedicatedTenantDatabase();

    placeClient($this->w->c1);
})->throws(RuntimeException::class, 'top organization');

it('fails closed when a placement names a database that is not configured', function () {
    TenantPlacement::create([
        'root_organization_id' => $this->w->g1->id,
        'strategy' => DatabaseStrategy::Dedicated,
        'database' => 'nowhere',
        'status' => PlacementStatus::Active,
    ]);

    actInOrganization(createMember($this->w->c1), $this->w->c1);

    TenantNote::query()->get();
})->throws(RuntimeException::class, 'not configured');

it('refuses a database that has no tenant tables yet', function () {
    TenantDatabases::register('empty', ['database' => config('database.connections.'.config('database.default').'.database').'_empty']);
    ensureTestDatabase(config('database.connections.tenant_empty.database'));

    placeClient($this->w->g1, 'empty');
})->throws(RuntimeException::class, 'tenants:migrate');

it('places a new client of a region in that region\'s database', function () {
    dedicatedTenantDatabase('eu');
    config(['tenant_databases.regions' => ['eu' => 'eu']]);

    $client = createGroup($this->w->partnerA, 'EU client', ['region' => 'eu']);

    expect(TenantPlacement::query()->where('root_organization_id', $client->id)->sole())
        ->strategy->toBe(DatabaseStrategy::Regional)
        ->database->toBe('eu')
        // Existing clients never move by themselves.
        ->and(TenantPlacement::query()->count())->toBe(1);

    $note = TenantNote::create(['title' => 'eu', 'organization_id' => $client->id]);
    expect($note->getConnectionName())->toBe('tenant_eu');
});

it('audits every placement', function () {
    dedicatedTenantDatabase();

    placeClient($this->w->g1);

    $entry = AuditLog::query()->where('action', 'tenant_database.placed')->sole();
    expect($entry->organization_id)->toBe($this->w->g1->id)
        // JSON columns may reorder keys.
        ->and($entry->old_values)->toEqual(['strategy' => 'shared', 'database' => null])
        ->and($entry->new_values)->toEqual(['strategy' => 'dedicated', 'database' => 'dedicated'])
        ->and($entry->reason)->toBe('Test setup');
});

it('blocks moving a unit into a client tree kept in another database', function () {
    dedicatedTenantDatabase();
    placeClient($this->w->g1);

    app(HierarchyService::class)->move($this->w->b1, $this->w->c3, 'Reorganization');
})->throws(HierarchyViolation::class);

it('pauses changes, but not reading, while the data moves', function () {
    Route::middleware(['api', 'auth:sanctum', 'org'])->prefix('api/test-notes')->group(function () {
        Route::get('/', fn () => TenantNote::query()->pluck('title'));
        Route::patch('{id}', fn (Request $request, string $id) => tap(TenantNote::findOrFail($id))->update(['title' => $request->input('title')]));
    });

    dedicatedTenantDatabase();
    placeClient($this->w->g1);
    $note = TenantNote::create(['title' => 'a', 'organization_id' => $this->w->c1->id]);
    TenantPlacement::query()->where('root_organization_id', $this->w->g1->id)->update(['status' => PlacementStatus::Moving->value]);
    app(TenantDatabases::class)->forget(); // a new request reads placements fresh

    $token = orgToken(createMember($this->w->c1, MembershipType::Staff), $this->w->c1);

    $this->asToken($token)->getJson('/api/test-notes')->assertOk()->assertExactJson(['a']);
    $this->asToken($token)->withHeader('Accept-Language', 'bn')->patchJson("/api/test-notes/{$note->id}", ['title' => 'b'])
        ->assertStatus(503)
        ->assertJsonPath('code', 'data_moving');

    expect(storedNote($note)->title)->toBe('a');
});

it('places a client from the command line with a reason', function () {
    dedicatedTenantDatabase();

    $this->artisan('tenants:place', ['organization' => $this->w->g1->id, 'database' => 'dedicated', '--force' => true])
        ->expectsOutputToContain('--reason')
        ->assertExitCode(2);

    $this->artisan('tenants:place', ['organization' => $this->w->g1->id, 'database' => 'dedicated', '--reason' => 'Large client', '--force' => true])
        ->assertSuccessful();

    $this->artisan('tenants:list')->expectsOutputToContain('dedicated')->assertSuccessful();

    expect(app(TenantDatabases::class)->forRoot($this->w->g1->id))->toBe('tenant_dedicated');
});

it('migrates the tenant tables of every configured database', function () {
    dedicatedTenantDatabase();

    $this->artisan('tenants:migrate')->expectsOutputToContain('Migrating dedicated')->assertSuccessful();
});
