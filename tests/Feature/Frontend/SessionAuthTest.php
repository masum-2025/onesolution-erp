<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Browser app sign-in: cookie session instead of tokens. The chosen context
 * lives in the server-side session, is bound to the user, expires like a
 * context token, and never comes from the request.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->owner = createMember($this->w->c1);
    $this->withHeader('Origin', config('app.url'));
});

it('signs in with a session and returns no token', function () {
    $response = $this->postJson('/session/login', ['email' => $this->owner->email, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('contexts.organizations.0.organization_id', $this->w->c1->id);

    expect($response->json())->not->toHaveKey('token')
        ->and(Auth::guard('web')->id())->toBe($this->owner->id)
        ->and(AuditLog::where('action', 'auth.login')->where('actor_user_id', $this->owner->id)->exists())->toBeTrue();
});

it('refuses a wrong password with a translated message', function () {
    $this->withHeader('X-Locale', 'bn')
        ->postJson('/session/login', ['email' => $this->owner->email, 'password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => __('tenancy.errors.credentials', [], 'bn')]);

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('throttles repeated session sign-in attempts', function () {
    RateLimiter::clear('tenancy-login');

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/session/login', ['email' => $this->owner->email, 'password' => 'wrong'])->assertUnprocessable();
    }

    $this->postJson('/session/login', ['email' => $this->owner->email, 'password' => 'wrong'])->assertTooManyRequests();
});

it('needs a chosen organization before tenant data', function () {
    $this->postJson('/session/login', ['email' => $this->owner->email, 'password' => 'password'])->assertOk();

    $this->getJson('/api/organizations')->assertForbidden()->assertJsonPath('code', 'no_context');
});

it('enters an organization and scopes every call to it', function () {
    spaSession($this, $this->owner, $this->w->c1);

    $ids = collect($this->getJson('/api/organizations')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($this->w->c1->id, $this->w->b1->id, $this->w->d1->id)
        ->not->toContain($this->w->c2->id, $this->w->c3->id, $this->w->c4->id)
        ->and(AuditLog::where('action', 'auth.context_entered')->where('organization_id', $this->w->c1->id)->exists())->toBeTrue();
});

it('returns 404 for organizations of another company or partner', function () {
    spaSession($this, $this->owner, $this->w->c1);

    $this->getJson("/api/organizations/{$this->w->c2->id}")->assertNotFound();
    $this->getJson("/api/organizations/{$this->w->c4->id}")->assertNotFound();
    $this->getJson("/api/organizations/{$this->w->c4->id}/rules")->assertNotFound();
});

it('ignores an organization id sent in the body, query or headers', function () {
    spaSession($this, $this->owner, $this->w->c1);

    $this->withHeader('X-Organization-Id', $this->w->c4->id)
        ->getJson("/api/organizations?organization_id={$this->w->c4->id}")
        ->assertOk()
        ->assertJsonMissing(['id' => $this->w->c4->id]);
});

it('refuses to enter an organization the user is not a member of', function () {
    spaSession($this, $this->owner);

    $this->postJson('/session/context', ['organization_id' => $this->w->c4->id])->assertForbidden();
    $this->getJson('/api/organizations')->assertForbidden()->assertJsonPath('code', 'no_context');
});

it('drops the context when the membership is suspended', function () {
    spaSession($this, $this->owner, $this->w->c1);

    OrganizationMembership::where('user_id', $this->owner->id)->update(['status' => MembershipStatus::Suspended]);

    $this->getJson('/api/organizations')->assertForbidden();
});

it('reads the context only when the session entry belongs to the signed-in user', function (bool $sameUser, int $status) {
    $other = createMember($this->w->c1);

    $this->actingAs($this->owner, 'web')
        ->withSession(['tenancy_context' => [
            'type' => 'organization',
            'id' => $this->w->c1->id,
            'user_id' => $sameUser ? $this->owner->id : $other->id,
            'expires_at' => now()->addHour()->getTimestamp(),
        ]])
        ->getJson('/api/organizations')
        ->assertStatus($status);
})->with([
    'own entry' => [true, 200],
    'entry of another user' => [false, 403],
]);

it('ends the context when it expires', function () {
    $this->actingAs($this->owner, 'web')
        ->withSession(['tenancy_context' => ['type' => 'organization', 'id' => $this->w->c1->id, 'user_id' => $this->owner->id, 'expires_at' => now()->subMinute()->getTimestamp()]])
        ->getJson('/api/organizations')
        ->assertForbidden()
        ->assertJsonPath('code', 'no_context');

    expect(session('tenancy_context'))->toBeNull();
});

it('uses the rule tenancy.token_ttl_minutes for the context lifetime', function () {
    platformRule('tenancy.token_ttl_minutes', 30);
    spaSession($this, $this->owner, $this->w->c1);

    $expires = $this->getJson('/api/me')->assertOk()->json('data.context.expires_at');

    expect(now()->diffInMinutes($expires))->toBeGreaterThan(28)->toBeLessThanOrEqual(30);
});

it('switches context with a fresh session and an audit entry', function () {
    $user = createMember($this->w->c1);
    app(AddMember::class)->handle($this->w->c3, $user, MembershipType::Staff);

    spaSession($this, $user, $this->w->c1);
    $this->postJson('/session/context', ['organization_id' => $this->w->c3->id])->assertOk();
    app(CurrentContext::class)->clear();

    $ids = collect($this->getJson('/api/organizations')->json('data'))->pluck('id');

    expect($ids)->toContain($this->w->c3->id)->not->toContain($this->w->c1->id)
        ->and(AuditLog::where('action', 'auth.context_entered')->where('actor_user_id', $user->id)->count())->toBe(2);
});

it('signs out and invalidates the session', function () {
    spaSession($this, $this->owner, $this->w->c1);

    $this->postJson('/session/logout')->assertOk();

    expect(Auth::guard('web')->check())->toBeFalse();
    $this->getJson('/api/me')->assertUnauthorized();
});

it('lets partner staff use the partner console, only for their own clients', function () {
    $staff = createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner);
    spaSession($this, $staff, partner: $this->w->partnerA);

    $ids = collect($this->getJson('/api/partner/organizations')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($this->w->c1->id)->not->toContain($this->w->c4->id);
    $this->getJson("/api/partner/organizations/{$this->w->c4->id}")->assertNotFound();
    // A partner console session is not a client context.
    $this->getJson('/api/organizations')->assertForbidden();
});

it('keeps token clients working and never mixes them with a session', function () {
    $token = orgToken($this->owner, $this->w->c1);

    // No browser Origin: plain API client.
    $this->withHeaders(['Origin' => null])
        ->withToken($token)
        ->getJson('/api/organizations')
        ->assertOk();
});

it('rejects unknown fields on session endpoints', function () {
    $this->postJson('/session/login', ['email' => $this->owner->email, 'password' => 'password', 'organization_id' => $this->w->c4->id])
        ->assertUnprocessable();
});

it('asks guests to sign in', function () {
    $this->postJson('/session/context', ['organization_id' => $this->w->c1->id])->assertUnauthorized();
    $this->getJson('/api/me')->assertUnauthorized();
});

it('refuses a partner console the user does not belong to', function () {
    $user = User::factory()->create();

    spaSession($this, $user);

    $this->postJson('/session/context', ['partner_id' => $this->w->partnerB->id])->assertForbidden();
});
