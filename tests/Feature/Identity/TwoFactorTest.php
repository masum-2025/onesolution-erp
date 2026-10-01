<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Identity\Models\MfaReset;
use App\Platform\Identity\Models\Passkey;
use App\Platform\Identity\Models\RecoveryCode;
use App\Platform\Identity\Models\TotpSecret;
use App\Platform\Identity\Models\UserSession;
use App\Platform\Identity\Services\TwoFactorService;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\Enums\RuleScope;
use App\Platform\Rules\Enums\RuleValueStatus;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Rules\RuleCache;
use App\Platform\Rules\RuleTargets;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Exceptions\OrganizationAccessDenied;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\FakeAuthenticator;
use Tests\TestCase;

/*
 * Two-step sign-in (Phase 8-1): the authenticator app, recovery codes,
 * passkeys, step-up before sensitive actions, organizations requiring it,
 * and admin resets with a second approver.
 */

/** The address test requests go to (scheme, host and port), like a browser's origin. */
function origin(): string
{
    $url = parse_url(config('app.url'));

    return $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
}

beforeEach(function () {
    $this->world = tenancyWorld();
    $this->company = $this->world->c1;
    $this->user = createMember($this->company, MembershipType::Staff);
    $this->withHeader('Origin', config('app.url'));
});

/** The app code for the current 30-second step (or one next to it). */
function totp(string $secret, int $offset = 0): string
{
    return (new Google2FA)->oathTotp($secret, TwoFactorService::currentStep() + $offset);
}

/** Like a browser: send the session cookie the last response set. */
function inSession(TestCase $test): TestCase
{
    return $test->withCredentials()->withCookie(config('session.cookie'), app('session.store')->getId());
}

function signInWithPassword(TestCase $test, User $user): TestResponse
{
    return inSession($test)->postJson('/session/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
}

function signOut(TestCase $test): void
{
    inSession($test)->postJson('/session/logout')->assertOk();
    app(CurrentContext::class)->clear();
    // Like the next request in a new process: no guard remembers the person.
    auth()->forgetGuards();
}

/**
 * Signs the person in (no second step yet), sets up the app and signs out.
 *
 * @return array{0: string, 1: list<string>} The secret and the recovery codes.
 */
function setUpApp(TestCase $test, User $user): array
{
    signInWithPassword($test, $user);
    $start = inSession($test)->postJson('/api/me/security/totp')->assertOk();
    expect($start->json('data.qr'))->toStartWith('data:image/svg+xml;base64,')
        ->and($start->json('data.uri'))->toStartWith('otpauth://totp/');

    $secret = $start->json('data.secret');
    $codes = inSession($test)->postJson('/api/me/security/totp/confirm', ['code' => totp($secret)])->assertOk()->json('data.recovery_codes');
    signOut($test);

    return [$secret, $codes];
}

function requireTwoFactor(Organization $organization, string $key = 'identity.mfa_required', mixed $value = true): void
{
    ruleService()->set(app(RuleTargets::class)->organization($organization->fresh()), $key, RuleMode::Set, $value, 'Test setup', trusted: true);
}

function membershipOf(User $user, Organization $organization): OrganizationMembership
{
    return OrganizationMembership::query()->where('user_id', $user->id)->where('organization_id', $organization->id)->firstOrFail();
}

it('sets up the authenticator app with a first code and gives ten recovery codes once', function () {
    signInWithPassword($this, $this->user);
    $secret = inSession($this)->postJson('/api/me/security/totp')->assertOk()->json('data.secret');

    inSession($this)->postJson('/api/me/security/totp/confirm', ['code' => '12345'])->assertStatus(422)->assertJsonPath('code', 'invalid_code');
    $codes = inSession($this)->postJson('/api/me/security/totp/confirm', ['code' => totp($secret)])->assertOk()->json('data.recovery_codes');

    expect($codes)->toHaveCount(10)
        ->and($codes[0])->toMatch('/^[2-9A-Z]{5}-[2-9A-Z]{5}$/')
        ->and($this->user->fresh()->hasTwoFactor())->toBeTrue()
        // Stored encrypted and hashed only.
        ->and(DB::table('user_totp')->value('secret'))->not->toContain($secret)
        ->and(RecoveryCode::query()->pluck('code_hash')->all())->not->toContain($codes[0])
        ->and(AuditLog::where('action', 'identity.totp_enabled')->exists())->toBeTrue();

    // Setting it up again needs removing it first.
    inSession($this)->postJson('/api/me/security/totp')->assertStatus(409)->assertJsonPath('code', 'already_enabled');
    inSession($this)->getJson('/api/me/security')->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.totp', true)
        ->assertJsonPath('data.recovery_codes_left', 10);
});

it('asks for the second step after the password, and signs in only then', function () {
    [$secret] = setUpApp($this, $this->user);

    signInWithPassword($this, $this->user)
        ->assertJsonPath('two_factor.methods', ['totp', 'recovery_code'])
        ->assertJsonMissingPath('contexts');
    inSession($this)->getJson('/api/me')->assertUnauthorized();

    inSession($this)->postJson('/session/two-factor', ['code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'invalid_code');
    inSession($this)->postJson('/session/two-factor', ['code' => totp($secret, 1)])->assertOk()->assertJsonStructure(['contexts']);

    inSession($this)->getJson('/api/me')->assertOk()->assertJsonPath('data.user.two_factor.enabled', true);
    expect(AuditLog::where('action', 'auth.password_accepted')->count())->toBe(1)
        ->and(AuditLog::where('action', 'auth.login')->latest('id')->first()->new_values)->toBe(['second_step' => 'totp'])
        ->and(AuditLog::where('action', 'auth.second_step_failed')->count())->toBe(1);
});

it('never accepts the same app code twice', function () {
    [$secret] = setUpApp($this, $this->user);
    $code = totp($secret, 1);

    signInWithPassword($this, $this->user);
    inSession($this)->postJson('/session/two-factor', ['code' => $code])->assertOk();
    signOut($this);

    signInWithPassword($this, $this->user);
    inSession($this)->postJson('/session/two-factor', ['code' => $code])->assertStatus(422)->assertJsonPath('code', 'invalid_code');
    // An older code than the last one used is refused as well.
    inSession($this)->postJson('/session/two-factor', ['code' => totp($secret)])->assertStatus(422);
});

it('accepts each recovery code once, whatever the spacing and case', function () {
    [, $codes] = setUpApp($this, $this->user);

    signInWithPassword($this, $this->user);
    inSession($this)->postJson('/session/two-factor', ['recovery_code' => strtolower(str_replace('-', ' ', $codes[0]))])->assertOk();
    signOut($this);

    signInWithPassword($this, $this->user);
    inSession($this)->postJson('/session/two-factor', ['recovery_code' => $codes[0]])->assertStatus(422)->assertJsonPath('field', 'recovery_code');
    inSession($this)->postJson('/session/two-factor', ['recovery_code' => $codes[1]])->assertOk();

    expect(RecoveryCode::query()->whereNull('used_at')->count())->toBe(8)
        ->and(AuditLog::where('action', 'identity.recovery_code_used')->count())->toBe(2);
});

it('ends a waiting sign-in after too many wrong codes or too much time', function () {
    [$secret] = setUpApp($this, $this->user);

    signInWithPassword($this, $this->user);
    foreach (range(1, 4) as $try) {
        inSession($this)->postJson('/session/two-factor', ['code' => '000000'])->assertJsonPath('code', 'invalid_code');
    }
    inSession($this)->postJson('/session/two-factor', ['code' => '000000'])->assertJsonPath('code', 'challenge_ended');
    // Even the right code needs the password again now.
    inSession($this)->postJson('/session/two-factor', ['code' => totp($secret, 1)])->assertJsonPath('code', 'challenge_ended');

    signInWithPassword($this, $this->user);
    $this->travel(6)->minutes();
    inSession($this)->postJson('/session/two-factor', ['code' => totp($secret)])->assertJsonPath('code', 'challenge_ended');
    inSession($this)->getJson('/api/me')->assertUnauthorized();
});

it('gives API clients a short-lived second-step token instead of an access token', function () {
    [$secret, $codes] = setUpApp($this, $this->user);

    $login = $this->postJson('/api/auth/login', ['email' => $this->user->email, 'password' => 'password'])->assertOk()
        ->assertJsonMissingPath('token')
        ->assertJsonPath('two_factor.methods', ['totp', 'recovery_code']);
    $token = $login->json('two_factor.token');

    $this->postJson('/api/auth/two-factor', ['token' => $token, 'code' => '000000'])->assertStatus(422);
    $this->postJson('/api/auth/two-factor', ['token' => $token, 'code' => totp($secret, 1), 'extra' => 1])->assertJsonValidationErrors('extra');
    $this->postJson('/api/auth/two-factor', ['token' => $token, 'recovery_code' => $codes[2]])->assertOk()->assertJsonStructure(['token', 'contexts']);

    // The second-step token works once.
    $this->postJson('/api/auth/two-factor', ['token' => $token, 'recovery_code' => $codes[3]])->assertJsonPath('code', 'challenge_ended');
});

it('adds a passkey and signs in with it alone, bound to this address', function () {
    signInWithPassword($this, $this->user);
    $device = new FakeAuthenticator;

    $options = inSession($this)->postJson('/api/me/security/passkeys/options')->assertOk()->json('data');
    expect($options['rp']['id'])->toBe('localhost')
        ->and($options['authenticatorSelection']['userVerification'])->toBe('required')
        ->and($options['authenticatorSelection']['residentKey'])->toBe('required');

    $added = inSession($this)->postJson('/api/me/security/passkeys', ['name' => 'My laptop', 'credential' => $device->create($options, origin())])->assertCreated();
    // The first second step comes with recovery codes.
    expect($added->json('data.recovery_codes'))->toHaveCount(10)
        ->and($this->user->fresh()->hasTwoFactor())->toBeTrue();
    signOut($this);

    // No password: any passkey of this address.
    $options = inSession($this)->postJson('/session/passkey/options')->assertOk()->json('data');
    expect($options['allowCredentials'] ?? [])->toBe([]);
    inSession($this)->postJson('/session/passkey', ['credential' => $device->get($options, origin())])->assertOk()->assertJsonStructure(['contexts']);
    inSession($this)->getJson('/api/me')->assertOk()->assertJsonPath('data.user.id', $this->user->id);

    $passkey = Passkey::query()->sole();
    expect($passkey->counter)->toBe(1)
        ->and($passkey->last_used_at)->not->toBeNull()
        ->and($passkey->rp_id)->toBe('localhost')
        ->and(AuditLog::where('action', 'identity.passkey_added')->exists())->toBeTrue();

    inSession($this)->getJson('/api/me/security')->assertOk()
        ->assertJsonPath('data.passkeys.0.name', 'My laptop')
        ->assertJsonPath('data.passkeys.0.here', true)
        ->assertJsonMissingPath('data.passkeys.0.public_key');
});

it('refuses passkey answers from another address, replayed, or for someone else', function () {
    signInWithPassword($this, $this->user);
    $device = new FakeAuthenticator;
    $options = inSession($this)->postJson('/api/me/security/passkeys/options')->json('data');
    inSession($this)->postJson('/api/me/security/passkeys', ['name' => 'Phone', 'credential' => $device->create($options, origin())])->assertCreated();
    signOut($this);

    // A look-alike site asks the device to sign: the origin is wrong.
    $options = inSession($this)->postJson('/session/passkey/options')->json('data');
    inSession($this)->postJson('/session/passkey', ['credential' => $device->get($options, 'http://localhost.evil.test')])->assertJsonPath('code', 'passkey_failed');

    // A recorded answer cannot be sent again (the challenge is single use).
    $options = inSession($this)->postJson('/session/passkey/options')->json('data');
    $answer = $device->get($options, origin());
    inSession($this)->postJson('/session/passkey', ['credential' => $answer])->assertOk();
    signOut($this);
    inSession($this)->postJson('/session/passkey', ['credential' => $answer])->assertJsonPath('code', 'passkey_failed');

    // As the second step of another person's password sign-in, this passkey does not count.
    $other = createMember($this->company, MembershipType::Staff);
    [$otherSecret] = setUpApp($this, $other);
    signInWithPassword($this, $other);
    $options = inSession($this)->postJson('/session/passkey/options')->json('data');
    inSession($this)->postJson('/session/passkey', ['credential' => $device->get($options, origin(), 'localhost')])->assertJsonPath('code', 'passkey_failed');
    inSession($this)->getJson('/api/me')->assertUnauthorized();
});

it('takes a passkey as the second step of a password sign-in', function () {
    signInWithPassword($this, $this->user);
    $device = new FakeAuthenticator;
    $options = inSession($this)->postJson('/api/me/security/passkeys/options')->json('data');
    inSession($this)->postJson('/api/me/security/passkeys', ['name' => 'Phone', 'credential' => $device->create($options, origin())])->assertCreated();
    signOut($this);

    signInWithPassword($this, $this->user)->assertJsonPath('two_factor.methods', ['passkey', 'recovery_code']);
    $options = inSession($this)->postJson('/session/passkey/options')->assertOk()->json('data');
    expect($options['allowCredentials'])->toHaveCount(1);

    inSession($this)->postJson('/session/passkey', ['credential' => $device->get($options, origin())])->assertOk();
    inSession($this)->getJson('/api/me')->assertOk();
});

it('asks again before a sensitive action when the last second step is old', function () {
    [$secret] = setUpApp($this, $this->user);
    signInWithPassword($this, $this->user);
    inSession($this)->postJson('/session/two-factor', ['code' => totp($secret, 1)])->assertOk();

    // Just signed in with the app: recent enough.
    inSession($this)->postJson('/api/me/security/recovery-codes')->assertOk();

    $this->travel(16)->minutes();
    inSession($this)->postJson('/api/me/security/recovery-codes')->assertForbidden()
        ->assertJsonPath('code', 'step_up_required')
        ->assertJsonPath('methods', ['totp', 'recovery_code']);

    inSession($this)->postJson('/api/me/security/confirm', ['code' => '000000'])->assertJsonPath('code', 'invalid_code');
    inSession($this)->postJson('/api/me/security/confirm', ['code' => totp($secret)])->assertOk();
    inSession($this)->postJson('/api/me/security/recovery-codes')->assertOk();
});

it('ends an API second-step token after too many wrong codes (Phase 11)', function () {
    [$secret] = setUpApp($this, $this->user);
    $token = $this->postJson('/api/auth/login', ['email' => $this->user->email, 'password' => 'password'])->json('two_factor.token');

    foreach (range(1, (int) config('identity.two_factor.max_attempts') - 1) as $try) {
        $this->postJson('/api/auth/two-factor', ['token' => $token, 'code' => '000000'])->assertJsonPath('code', 'invalid_code');
    }
    $this->postJson('/api/auth/two-factor', ['token' => $token, 'code' => '000000'])->assertJsonPath('code', 'challenge_ended');
    // Even the right code needs the password again now.
    $this->postJson('/api/auth/two-factor', ['token' => $token, 'code' => totp($secret, 1)])->assertJsonPath('code', 'challenge_ended');
});

it('confirms a sensitive action with a passkey (Phase 11)', function () {
    signInWithPassword($this, $this->user);
    $device = new FakeAuthenticator;
    $options = inSession($this)->postJson('/api/me/security/passkeys/options')->json('data');
    inSession($this)->postJson('/api/me/security/passkeys', ['name' => 'Phone', 'credential' => $device->create($options, origin())])->assertCreated();

    $this->travel(16)->minutes();
    inSession($this)->postJson('/api/me/security/recovery-codes')->assertForbidden()->assertJsonPath('code', 'step_up_required');

    $options = inSession($this)->postJson('/api/me/security/confirm/options')->assertOk()->json('data');
    inSession($this)->postJson('/api/me/security/confirm', ['credential' => $device->get($options, origin())])->assertOk();
    inSession($this)->postJson('/api/me/security/recovery-codes')->assertOk();
});

it('lets API clients confirm a sensitive action with a code in a header', function () {
    $owner = createMember($this->company);
    [$secret] = setUpApp($this, $owner);
    $token = orgToken($owner, $this->company);

    $this->asToken($token)->postJson("/api/organizations/{$this->company->id}/roles", [])
        ->assertForbidden()->assertJsonPath('code', 'step_up_required');

    // The code passes the check; the (empty) role itself is then validated.
    $this->asToken($token)->withHeader('X-Two-Factor-Code', totp($secret, 1))
        ->postJson("/api/organizations/{$this->company->id}/roles", [])
        ->assertStatus(422);
});

it('requires two-step sign-in where the organization says so, after a grace period', function () {
    requireTwoFactor($this->world->g1);

    // Inside the grace period: works, with a reminder and where the rule comes from.
    spaSession($this, $this->user, $this->company);
    inSession($this)->getJson('/api/me')->assertOk()->assertJsonPath('data.context.id', $this->company->id);
    expect(inSession($this)->getJson('/api/me')->json('data.user.two_factor.setup_due_at'))->not->toBeNull();
    inSession($this)->getJson('/api/me/security')->assertOk()
        ->assertJsonPath('data.requirement.required', true)
        ->assertJsonPath('data.requirement.source.level', 'group')
        ->assertJsonPath('data.requirement.source.name', 'G1');

    // Grace over: the organization closes, the security page stays open.
    $this->user->forceFill(['mfa_required_since' => now()->subDays(8)])->save();
    auth()->forgetGuards();
    inSession($this)->getJson('/api/me')->assertOk()->assertJsonPath('data.context', null);
    inSession($this)->postJson('/session/context', ['organization_id' => $this->company->id])
        ->assertForbidden()->assertJsonPath('code', 'two_factor_required');

    $secret = inSession($this)->postJson('/api/me/security/totp')->assertOk()->json('data.secret');
    inSession($this)->postJson('/api/me/security/totp/confirm', ['code' => totp($secret)])->assertOk();
    inSession($this)->postJson('/session/context', ['organization_id' => $this->company->id])->assertOk();

    // A company without the rule never asked (another account).
    $elsewhere = createMember($this->world->c3, MembershipType::Staff);
    $elsewhere->forceFill(['mfa_required_since' => now()->subDays(30)])->save();
    expect(orgToken($elsewhere, $this->world->c3))->toBeString();
});

it('checks the requirement on every request, not only when entering', function () {
    requireTwoFactor($this->company);
    $token = orgToken($this->user, $this->company);
    $this->asToken($token)->getJson("/api/organizations/{$this->company->id}")->assertOk();

    $this->user->forceFill(['mfa_required_since' => now()->subDays(8)])->save();
    $this->asToken($token)->getJson("/api/organizations/{$this->company->id}")->assertForbidden()->assertJsonPath('code', 'two_factor_required');
});

it('can require it for one role only', function () {
    $role = makeRole($this->company, ['members.manage'], 'Finance');
    $finance = staffWithRoles($this->company, $role);
    // Role-level values are written straight to the table (no role tooling yet, as in RuleEngineTest).
    RuleValue::create([
        'rule_key' => 'identity.mfa_required', 'scope_type' => RuleScope::Role, 'scope_id' => $role->id,
        'mode' => RuleMode::Set, 'value' => true, 'version' => 1, 'status' => RuleValueStatus::Active, 'reason' => 'Finance handles money',
    ]);
    app(RuleCache::class)->flush(RuleScope::Role, $role->id);

    foreach ([$finance, $this->user] as $person) {
        $person->forceFill(['mfa_required_since' => now()->subDays(8)])->save();
    }

    expect(fn () => orgToken($finance, $this->company))->toThrow(OrganizationAccessDenied::class)
        ->and(orgToken($this->user, $this->company))->toBeString();
});

it('asks portal people only when the portal rule says so', function () {
    $parent = createMember($this->company, MembershipType::Portal);
    $parent->forceFill(['mfa_required_since' => now()->subDays(8)])->save();
    requireTwoFactor($this->company);

    expect(actInOrganization($parent, $this->company)->hasOrganization())->toBeTrue();

    requireTwoFactor($this->company, 'identity.mfa_required_portal');
    app(CurrentContext::class)->clear();
    expect(fn () => actInOrganization($parent, $this->company))->toThrow(OrganizationAccessDenied::class);
});

it('requires it of partner staff unless the platform decides otherwise', function () {
    $staff = createPartnerStaff($this->world->partnerA, PartnerUserRole::Owner);
    expect(partnerToken($staff, $this->world->partnerA))->toBeString();

    $staff->forceFill(['mfa_required_since' => now()->subDays(8)])->save();
    expect(fn () => partnerToken($staff, $this->world->partnerA))->toThrow(OrganizationAccessDenied::class);

    platformRule('identity.mfa_required_partner_staff', false);
    expect(partnerToken($staff, $this->world->partnerA))->toBeString();
});

it('keeps the last second step while the organization requires one', function () {
    [$secret] = setUpApp($this, $this->user);
    requireTwoFactor($this->company);
    signInWithPassword($this, $this->user);
    inSession($this)->postJson('/session/two-factor', ['code' => totp($secret, 1)])->assertOk();
    inSession($this)->postJson('/session/context', ['organization_id' => $this->company->id])->assertOk();

    inSession($this)->deleteJson('/api/me/security/totp')->assertStatus(409)->assertJsonPath('code', 'still_required');
    expect(TotpSecret::query()->count())->toBe(1);
});

it('resets a lost second step only with a second admin, and only for people who work here alone', function () {
    [$secret] = setUpApp($this, $this->user);
    $role = makeRole($this->company, ['security.mfa_reset'], 'Security');
    $adminA = staffWithRoles($this->company, $role);
    $adminB = staffWithRoles($this->company, $role);
    $membership = membershipOf($this->user, $this->company);
    UserSession::query()->forceCreate(['user_id' => $this->user->id, 'session_hash' => UserSession::hashOf('lost-phone'), 'last_seen_at' => now()]);
    $this->user->createToken('phone-app');

    $a = orgToken($adminA, $this->company);
    $b = orgToken($adminB, $this->company);
    $url = "/api/organizations/{$this->company->id}";

    // Not for oneself; a reason is needed.
    $this->asToken($a)->postJson("{$url}/members/".membershipOf($adminA, $this->company)->id.'/mfa-reset', ['reason' => 'Lost my phone'])->assertJsonPath('code', 'reset_self');
    $this->asToken($a)->postJson("{$url}/members/{$membership->id}/mfa-reset", [])->assertJsonValidationErrors('reason');

    $reset = $this->asToken($a)->postJson("{$url}/members/{$membership->id}/mfa-reset", ['reason' => 'Phone stolen, called the office'])->assertCreated()->json('data.id');
    $this->asToken($a)->postJson("{$url}/members/{$membership->id}/mfa-reset", ['reason' => 'Again please'])->assertJsonPath('code', 'reset_pending');
    $this->asToken($b)->getJson("{$url}/mfa-resets")->assertOk()->assertJsonPath('data.0.user.id', $this->user->id)->assertJsonPath('data.0.requested_by.id', $adminA->id);

    // The one who asked cannot approve.
    $this->asToken($a)->postJson("{$url}/mfa-resets/{$reset}/approve")->assertForbidden()->assertJsonPath('code', 'reset_same_person');
    $this->asToken($b)->postJson("{$url}/mfa-resets/{$reset}/approve")->assertOk();

    $user = $this->user->fresh();
    expect($user->hasTwoFactor())->toBeFalse()
        ->and(TotpSecret::query()->count())->toBe(0)
        ->and(RecoveryCode::query()->count())->toBe(0)
        ->and($user->tokens()->count())->toBe(0)
        ->and(UserSession::where('session_hash', UserSession::hashOf('lost-phone'))->value('revoked_at'))->not->toBeNull()
        ->and(MfaReset::query()->withoutGlobalScopes()->find($reset)->status)->toBe(MfaReset::APPROVED)
        ->and(AuditLog::whereIn('action', ['identity.mfa_reset_requested', 'identity.mfa_reset_approved'])->count())->toBe(2);

    $this->asToken($b)->postJson("{$url}/mfa-resets/{$reset}/approve")->assertJsonPath('code', 'reset_not_open');
    // The password still works; the person sets up a second step again.
    signInWithPassword($this, $this->user)->assertJsonStructure(['contexts']);
});

it('refuses resets for people who also work for other accounts, and without the permission', function () {
    setUpApp($this, $this->user);
    app(AddMember::class)->handle($this->world->c3, $this->user, MembershipType::Staff);

    $admin = staffWithRoles($this->company, makeRole($this->company, ['security.mfa_reset'], 'Security'));
    $plain = createMember($this->company, MembershipType::Staff);
    $membership = membershipOf($this->user, $this->company);
    $url = "/api/organizations/{$this->company->id}/members/{$membership->id}/mfa-reset";

    $this->asToken(orgToken($admin, $this->company))->postJson($url, ['reason' => 'Lost the phone'])->assertJsonPath('code', 'reset_elsewhere');
    $this->asToken(orgToken($plain, $this->company))->postJson($url, ['reason' => 'Lost the phone'])->assertForbidden();
});

it('keeps resets inside their account and partner', function () {
    setUpApp($this, $this->user);
    $admin = staffWithRoles($this->company, makeRole($this->company, ['security.mfa_reset'], 'Security'));
    $membership = membershipOf($this->user, $this->company);
    $reset = $this->asToken(orgToken($admin, $this->company))
        ->postJson("/api/organizations/{$this->company->id}/members/{$membership->id}/mfa-reset", ['reason' => 'Lost the phone'])
        ->assertCreated()->json('data.id');

    // Another account of the same partner, and another partner's client.
    foreach ([$this->world->c3, $this->world->c4] as $other) {
        $otherAdmin = staffWithRoles($other, makeRole($other, ['security.mfa_reset'], 'Security'));
        $token = orgToken($otherAdmin, $other);

        $this->asToken($token)->getJson("/api/organizations/{$this->company->id}/mfa-resets")->assertNotFound();
        $this->asToken($token)->postJson("/api/organizations/{$this->company->id}/mfa-resets/{$reset}/approve")->assertNotFound();
        $this->asToken($token)->postJson("/api/organizations/{$other->id}/mfa-resets/{$reset}/approve")->assertNotFound();
        $this->asToken($token)->postJson("/api/organizations/{$other->id}/members/{$membership->id}/mfa-reset", ['reason' => 'Not mine'])->assertNotFound();
    }

    expect($this->user->fresh()->hasTwoFactor())->toBeTrue();
});
