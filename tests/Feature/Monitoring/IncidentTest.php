<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Identity\Models\UserSession;
use App\Platform\Offline\Models\Device;
use App\Platform\PartnerApi\Models\PartnerApiKey;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/*
 * Phase 9-2, incident response: security:revoke cuts every way in for a
 * person, an organization or a partner at once, and is audited.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
});

function deviceFor($user, $organization): Device
{
    $device = new Device;
    $device->forceFill(['user_id' => $user->id, 'organization_id' => $organization->id, 'name' => 'Front desk laptop'])->save();

    return $device;
}

function openSession($user): UserSession
{
    $session = new UserSession;
    $session->forceFill(['user_id' => $user->id, 'session_hash' => UserSession::hashOf(Str::random(40)), 'last_seen_at' => now()])->save();

    return $session;
}

it('cuts a person off: tokens, browser sessions and offline devices', function () {
    $user = createMember($this->w->c1);
    $token = orgToken($user, $this->w->c1);
    $session = openSession($user);
    $device = deviceFor($user, $this->w->c1);

    $this->artisan('security:revoke', ['--user' => $user->email, '--reason' => 'Laptop stolen', '--force' => true])
        ->expectsOutputToContain('Revoked:')
        ->assertSuccessful();

    $this->asToken($token)->getJson('/api/me')->assertUnauthorized();
    expect($session->fresh()->revoked_at)->not->toBeNull()
        ->and($device->fresh()->revoked_at)->not->toBeNull()
        ->and($device->fresh()->wipe_requested_at)->not->toBeNull();

    $entry = AuditLog::query()->where('action', 'security.access_revoked')->sole();
    expect($entry->reason)->toBe('Laptop stolen')->and($entry->new_values)->toMatchArray(['scope' => 'user', 'tokens' => 1, 'sessions' => 1, 'devices' => 1]);
});

it('cuts an organization off without touching its neighbours', function () {
    $inside = createMember($this->w->b1);
    $outside = createMember($this->w->c2);
    $insideToken = orgToken($inside, $this->w->b1);
    $outsideToken = orgToken($outside, $this->w->c2);

    $this->artisan('security:revoke', ['--organization' => $this->w->c1->id, '--reason' => 'Compromised admin account', '--force' => true])->assertSuccessful();

    $this->asToken($insideToken)->getJson('/api/me')->assertUnauthorized();
    $this->asToken($outsideToken)->getJson('/api/me')->assertOk();
    expect(AuditLog::query()->where('action', 'security.access_revoked')->sole()->organization_id)->toBe($this->w->c1->id);
});

it('cuts a partner off: console tokens and API keys', function () {
    $owner = createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner);
    $consoleToken = partnerToken($owner, $this->w->partnerA);
    $this->asToken($consoleToken)->postJson('http://localhost/api/partner/api-keys', ['name' => 'Website', 'scopes' => PartnerApiKey::SCOPES])->assertCreated();

    $this->artisan('security:revoke', ['--partner' => $this->w->partnerA->id, '--reason' => 'Partner reported a breach', '--force' => true])->assertSuccessful();

    expect(PersonalAccessToken::query()->where('partner_id', $this->w->partnerA->id)->count())->toBe(0)
        ->and(PartnerApiKey::query()->where('partner_id', $this->w->partnerA->id)->whereNull('revoked_at')->count())->toBe(0);
});

it('needs exactly one target and a reason, and asks before acting', function () {
    $user = createMember($this->w->c1);

    $this->artisan('security:revoke', ['--user' => $user->email])->assertExitCode(2);
    $this->artisan('security:revoke', ['--user' => $user->email, '--partner' => 'x', '--reason' => 'Two targets'])->assertExitCode(2);
    $this->artisan('security:revoke', ['--user' => 'nobody@example.test', '--reason' => 'No such person'])->assertFailed();
    $this->artisan('security:revoke', ['--user' => $user->email, '--reason' => 'Changed my mind'])
        ->expectsConfirmation("Revoke every way in for user \"{$user->email}\"? People must sign in again.", 'no')
        ->assertFailed();

    expect(AuditLog::query()->where('action', 'security.access_revoked')->exists())->toBeFalse();
});
