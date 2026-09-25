<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Enums\PartnerStatus;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->user = User::factory()->create(['email' => 'owner@example.test', 'password' => 'correct-horse']);
    app(AddMember::class)->handle($this->w->c1, $this->user, MembershipType::Owner);
});

it('logs in and lists the contexts the user may enter', function () {
    $response = $this->postJson('/api/auth/login', ['email' => 'owner@example.test', 'password' => 'correct-horse'])
        ->assertOk()
        ->assertJsonPath('context', null)
        ->assertJsonPath('contexts.organizations.0.organization_id', $this->w->c1->id)
        ->assertJsonPath('contexts.partners', []);

    expect($response->json('token'))->toBeString()
        ->and(AuditLog::where('action', 'auth.login')->where('actor_user_id', $this->user->id)->exists())->toBeTrue();
});

it('gives the same answer for a wrong password and an unknown email', function (string $email, string $password) {
    $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'The email or password is incorrect.']);

    expect(AuditLog::where('action', 'auth.login_failed')->count())->toBe(1);
})->with([
    'wrong password' => ['owner@example.test', 'wrong'],
    'unknown email' => ['nobody@example.test', 'correct-horse'],
]);

it('throttles repeated login attempts', function () {
    RateLimiter::clear('tenancy-login');

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/auth/login', ['email' => 'owner@example.test', 'password' => 'wrong'])->assertUnprocessable();
    }

    $this->postJson('/api/auth/login', ['email' => 'owner@example.test', 'password' => 'wrong'])->assertTooManyRequests();
});

it('does not let a login token reach tenant data', function () {
    $loginToken = $this->postJson('/api/auth/login', ['email' => 'owner@example.test', 'password' => 'correct-horse'])
        ->json('token');

    $this->asToken($loginToken)->getJson('/api/organizations')
        ->assertForbidden()
        ->assertJsonPath('code', 'no_context');
});

it('enters an organization and revokes the previous token', function () {
    $loginToken = $this->postJson('/api/auth/login', ['email' => 'owner@example.test', 'password' => 'correct-horse'])
        ->json('token');

    $orgToken = $this->asToken($loginToken)->postJson('/api/auth/context', ['organization_id' => $this->w->c1->id])
        ->assertOk()
        ->assertJsonPath('context.type', 'organization')
        ->assertJsonPath('context.id', $this->w->c1->id)
        ->json('token');

    expect(PersonalAccessToken::findToken($loginToken))->toBeNull();
    $this->asToken($orgToken)->getJson('/api/organizations')->assertOk();
    $this->asToken($loginToken)->getJson('/api/organizations')->assertUnauthorized();
});

it('refuses to enter an organization the user is not a member of', function (string $key) {
    $token = orgToken($this->user, $this->w->c1);

    $this->asToken($token)->postJson('/api/auth/context', ['organization_id' => $this->w->{$key}->id])
        ->assertForbidden()
        ->assertJsonPath('code', 'not_member');
})->with(['b1', 'c2', 'c4']);

it('refuses a suspended membership', function () {
    OrganizationMembership::where('user_id', $this->user->id)->update(['status' => MembershipStatus::Suspended]);

    $this->asToken(tokenWithoutChecks($this->user, $this->w->c1))->getJson('/api/organizations')
        ->assertForbidden()
        ->assertJsonPath('code', 'not_member');
});

it('blocks an existing token once the organization or an ancestor is suspended', function (string $key) {
    $token = orgToken($this->user, $this->w->c1);
    Organization::whereKey($this->w->{$key}->id)->update(['status' => OrganizationStatus::Suspended]);

    $this->asToken($token)->getJson('/api/organizations')
        ->assertForbidden()
        ->assertJsonPath('code', 'organization_inactive');
})->with(['c1', 'g1']);

it('blocks an existing token once the partner is suspended', function () {
    $token = orgToken($this->user, $this->w->c1);
    $this->w->partnerA->update(['status' => PartnerStatus::Suspended]);

    $this->asToken($token)->getJson('/api/organizations')
        ->assertForbidden()
        ->assertJsonPath('code', 'partner_inactive');
});

it('rejects expired tokens', function () {
    $token = orgToken($this->user, $this->w->c1);
    $this->travel(ruleFor('tenancy.token_ttl_minutes', $this->w->c1) + 1)->minutes();

    $this->asToken($token)->getJson('/api/organizations')->assertUnauthorized();
});

it('logs out by revoking the token', function () {
    $token = orgToken($this->user, $this->w->c1);

    $this->asToken($token)->postJson('/api/auth/logout')->assertOk();
    $this->asToken($token)->getJson('/api/organizations')->assertUnauthorized();
});

it('rejects both an organization and a partner in one context request', function () {
    $token = orgToken($this->user, $this->w->c1);

    $this->asToken($token)->postJson('/api/auth/context', [
        'organization_id' => $this->w->c1->id,
        'partner_id' => $this->w->partnerA->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('organization_id');
});

/**
 * A token bound to an organization without the membership checks, to prove
 * the checks also run on every request, not only when the token is issued.
 */
function tokenWithoutChecks(User $user, Organization $organization): string
{
    $token = $user->createToken('test', ['*'], now()->addHour());
    $token->accessToken->forceFill(['organization_id' => $organization->id])->save();

    return $token->plainTextToken;
}
