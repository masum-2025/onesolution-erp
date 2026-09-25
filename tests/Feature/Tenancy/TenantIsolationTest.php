<?php

use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Exceptions\MissingTenantContext;
use App\Platform\Tenancy\Exceptions\OrganizationAccessDenied;
use App\Platform\Tenancy\Exceptions\OrganizationChangeForbidden;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Fixtures\TenantNote;

/*
| A minimal CRUD API over the TenantNote fixture, wired exactly like a real
| module route: auth:sanctum + org middleware, plain Eloquent, no manual
| organization filtering. Isolation must come from BelongsToOrganization alone.
*/
beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum', 'org'])->prefix('api/test-notes')->group(function () {
        Route::get('/', fn () => TenantNote::query()->orderBy('title')->pluck('title'));
        Route::post('/', fn (Request $request) => TenantNote::create(['title' => $request->string('title')->toString()]));
        Route::get('{id}', fn (string $id) => TenantNote::findOrFail($id));
        Route::patch('{id}', fn (Request $request, string $id) => tap(TenantNote::findOrFail($id))->update(['title' => $request->input('title')]));
        Route::delete('{id}', fn (string $id) => TenantNote::findOrFail($id)->delete());
    });

    $this->w = tenancyWorld();

    // Seed notes as trusted system code (no context, explicit organization).
    $this->noteC1 = TenantNote::create(['title' => 'c1-note', 'organization_id' => $this->w->c1->id]);
    $this->noteB1 = TenantNote::create(['title' => 'b1-note', 'organization_id' => $this->w->b1->id]);
    $this->noteC2 = TenantNote::create(['title' => 'c2-note', 'organization_id' => $this->w->c2->id]);
    $this->noteC3 = TenantNote::create(['title' => 'c3-note', 'organization_id' => $this->w->c3->id]);
    $this->noteC4 = TenantNote::create(['title' => 'c4-note', 'organization_id' => $this->w->c4->id]);
    $this->noteG1 = TenantNote::create(['title' => 'g1-note', 'organization_id' => $this->w->g1->id]);

    $this->c1User = createMember($this->w->c1, MembershipType::Staff);
    $this->c1Token = orgToken($this->c1User, $this->w->c1);
});

it('lists only records of the current company subtree', function () {
    $this->asToken($this->c1Token)->getJson('/api/test-notes')
        ->assertOk()
        ->assertExactJson(['b1-note', 'c1-note']);
});

it('blocks reading, updating and deleting another company\'s records (IDOR)', function (string $note) {
    $id = $this->{$note}->id;

    $this->asToken($this->c1Token)->getJson("/api/test-notes/{$id}")->assertNotFound();
    $this->asToken($this->c1Token)->patchJson("/api/test-notes/{$id}", ['title' => 'hacked'])->assertNotFound();
    $this->asToken($this->c1Token)->deleteJson("/api/test-notes/{$id}")->assertNotFound();

    expect(TenantNote::withoutGlobalScope(OrganizationScope::class)->find($id)->title)->not->toBe('hacked');
})->with([
    'sister company, same group' => 'noteC2',
    'company in another group' => 'noteC3',
    'company of another partner' => 'noteC4',
    'parent group' => 'noteG1',
]);

it('returns 404 for guessed ids', function () {
    $this->asToken($this->c1Token)->getJson('/api/test-notes/'.Str::ulid())->assertNotFound();
});

it('ignores an organization id sent in the body or headers', function () {
    $this->asToken($this->c1Token)
        ->withHeader('X-Organization-Id', $this->w->c2->id)
        ->postJson('/api/test-notes', ['title' => 'new', 'organization_id' => $this->w->c2->id])
        ->assertSuccessful();

    expect(TenantNote::withoutGlobalScope(OrganizationScope::class)->where('title', 'new')->sole()->organization_id)
        ->toBe($this->w->c1->id);
});

it('sets organization_id automatically on create', function () {
    actInOrganization($this->c1User, $this->w->c1);

    expect(TenantNote::create(['title' => 'auto'])->organization_id)->toBe($this->w->c1->id);
});

it('allows creating for a branch inside the current company', function () {
    actInOrganization($this->c1User, $this->w->c1);

    expect(TenantNote::create(['title' => 'branch', 'organization_id' => $this->w->b1->id])->organization_id)
        ->toBe($this->w->b1->id);
});

it('refuses creating a record for another company', function () {
    actInOrganization($this->c1User, $this->w->c1);

    TenantNote::create(['title' => 'x', 'organization_id' => $this->w->c2->id]);
})->throws(OrganizationAccessDenied::class);

it('refuses changing organization_id of a record', function () {
    actInOrganization($this->c1User, $this->w->c1);

    $this->noteC1->fresh()->update(['organization_id' => $this->w->b1->id]);
})->throws(OrganizationChangeForbidden::class);

it('fails closed when there is no tenant context', function () {
    app(CurrentContext::class)->clear();

    TenantNote::query()->get();
})->throws(MissingTenantContext::class);

it('refuses to create without context unless an organization is named', function () {
    app(CurrentContext::class)->clear();

    TenantNote::create(['title' => 'orphan']);
})->throws(MissingTenantContext::class);

it('lets a group admin read all companies of the group only', function () {
    $admin = createMember($this->w->g1, MembershipType::Owner, AccessScope::Descendants);

    $this->asToken(orgToken($admin, $this->w->g1))->getJson('/api/test-notes')
        ->assertOk()
        ->assertExactJson(['b1-note', 'c1-note', 'c2-note', 'g1-note']);
});

it('keeps group admin access read-only for company data', function () {
    $admin = createMember($this->w->g1, MembershipType::Owner, AccessScope::Descendants);
    $token = orgToken($admin, $this->w->g1);

    $this->asToken($token)->patchJson("/api/test-notes/{$this->noteC1->id}", ['title' => 'x'])->assertForbidden();
    $this->asToken($token)->deleteJson("/api/test-notes/{$this->noteC1->id}")->assertForbidden();

    expect(TenantNote::withoutGlobalScope(OrganizationScope::class)->find($this->noteC1->id)->title)->toBe('c1-note');
});

it('limits a group member without descendant access to the group itself', function () {
    $member = createMember($this->w->g1, MembershipType::Staff, AccessScope::Own);

    $this->asToken(orgToken($member, $this->w->g1))->getJson('/api/test-notes')
        ->assertOk()
        ->assertExactJson(['g1-note']);
});
