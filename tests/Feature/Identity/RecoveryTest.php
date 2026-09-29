<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Identity\Jobs\SendOtp;
use App\Platform\Identity\Models\UserSession;
use App\Platform\Notifications\Contracts\SmsGateway;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Notifications\Services\LogSmsGateway;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/*
 * "Forgot password" (Phase 5C-1): a code to an address the account already
 * has, a new password, every other session ended, every address told.
 */

beforeEach(function () {
    $this->house = Partner::factory()->house()->create();
    partnerRule($this->house, 'notifications.sms_enabled', true);
    app()->instance(SmsGateway::class, new LogSmsGateway);
    $this->withHeader('Origin', config('app.url'));
    RateLimiter::clear('identity-start:127.0.0.1');
    Bus::fake([SendOtp::class]);

    $this->user = User::factory()->create(['email' => 'rahim@example.com']);
    $this->user->forceFill(['phone' => '+8801712345678', 'phone_verified_at' => now()])->save();
});

function recoveryCode(): string
{
    return Bus::dispatched(SendOtp::class)->last()->code;
}

it('resets the password with an email code, ends other sessions and tells every address', function () {
    // Signed in elsewhere, with a session and an API token.
    UserSession::query()->forceCreate(['user_id' => $this->user->id, 'session_hash' => UserSession::hashOf('other-device'), 'last_seen_at' => now()]);
    $this->user->createToken('old');

    $start = $this->postJson('/session/recovery', ['channel' => 'mail', 'email' => 'Rahim@Example.com'])->assertStatus(202);
    expect(Bus::dispatched(SendOtp::class)->last()->to)->toBe('rahim@example.com');

    $this->postJson('/session/recovery/verify', [
        'challenge_id' => $start->json('data.challenge_id'),
        'code' => recoveryCode(),
        'password' => 'New-secret-2026',
        'password_confirmation' => 'New-secret-2026',
    ])->assertOk();

    $user = $this->user->fresh();
    expect(Hash::check('New-secret-2026', $user->password))->toBeTrue()
        ->and($user->recovered_at)->not->toBeNull()
        ->and(Auth::guard('web')->id())->toBe($user->id)
        ->and(UserSession::where('session_hash', UserSession::hashOf('other-device'))->value('revoked_at'))->not->toBeNull()
        ->and($user->tokens()->count())->toBe(0)
        ->and(AuditLog::where('action', 'identity.recovered')->where('actor_user_id', $user->id)->exists())->toBeTrue()
        // The email and the phone both hear about it.
        ->and(NotificationDelivery::where('notification_key', 'identity.password_changed')->pluck('channel')->sort()->values()->all())->toBe(['mail', 'sms']);
});

it('still asks for the second step after a password reset (Phase 8-1)', function () {
    $this->user->forceFill(['mfa_enabled_at' => now()])->save();

    $start = $this->postJson('/session/recovery', ['channel' => 'mail', 'email' => 'rahim@example.com'])->assertStatus(202);
    $this->postJson('/session/recovery/verify', [
        'challenge_id' => $start->json('data.challenge_id'),
        'code' => recoveryCode(),
        'password' => 'New-secret-2026',
        'password_confirmation' => 'New-secret-2026',
    ])->assertOk()->assertJsonStructure(['two_factor' => ['methods', 'expires_at']])->assertJsonMissingPath('contexts');

    // The password changed, but a reset alone does not sign in.
    expect(Hash::check('New-secret-2026', $this->user->fresh()->password))->toBeTrue()
        ->and(Auth::guard('web')->id())->toBeNull();
});

it('works by phone for a verified phone only', function () {
    $start = $this->postJson('/session/recovery', ['channel' => 'sms', 'phone' => '01712345678', 'country_code' => 'BD'])->assertStatus(202);
    expect(Bus::dispatched(SendOtp::class)->last()->to)->toBe('+8801712345678');

    $this->user->forceFill(['phone_verified_at' => null])->save();
    $this->postJson('/session/recovery', ['channel' => 'sms', 'phone' => '01712345678', 'country_code' => 'BD'])->assertStatus(202);
    Bus::assertDispatchedTimes(SendOtp::class, 1);
});

it('answers unknown addresses exactly like known ones, and no code works for them', function () {
    $known = $this->postJson('/session/recovery', ['channel' => 'mail', 'email' => 'rahim@example.com'])->assertStatus(202);
    $unknown = $this->postJson('/session/recovery', ['channel' => 'mail', 'email' => 'nobody@example.com'])->assertStatus(202);

    expect(array_keys($unknown->json('data')))->toBe(array_keys($known->json('data')));
    Bus::assertDispatchedTimes(SendOtp::class, 1);

    $this->postJson('/session/recovery/verify', [
        'challenge_id' => $unknown->json('data.challenge_id'),
        'code' => recoveryCode(),
        'password' => 'New-secret-2026',
        'password_confirmation' => 'New-secret-2026',
    ])->assertUnprocessable()->assertJsonPath('code', 'code_wrong');
});

it('cannot be used with a sign-up code, and a code works once', function () {
    $start = $this->postJson('/session/recovery', ['channel' => 'mail', 'email' => 'rahim@example.com']);
    $body = ['challenge_id' => $start->json('data.challenge_id'), 'code' => recoveryCode()];

    $this->postJson('/session/signup/verify', $body)->assertJsonPath('code', 'code_expired');

    $reset = [...$body, 'password' => 'New-secret-2026', 'password_confirmation' => 'New-secret-2026'];
    $this->postJson('/session/recovery/verify', $reset)->assertOk();
    $this->postJson('/session/recovery/verify', $reset)->assertJsonPath('code', 'code_expired');
});

it('locks the email and phone for a while after a reset', function () {
    $start = $this->postJson('/session/recovery', ['channel' => 'mail', 'email' => 'rahim@example.com']);
    $this->postJson('/session/recovery/verify', [
        'challenge_id' => $start->json('data.challenge_id'),
        'code' => recoveryCode(),
        'password' => 'New-secret-2026',
        'password_confirmation' => 'New-secret-2026',
    ])->assertOk();

    // A thief who reset the password cannot also move the account to their own address.
    $this->postJson('/api/me/contact', ['channel' => 'mail', 'email' => 'thief@example.com', 'current_password' => 'New-secret-2026'])
        ->assertStatus(423)->assertJsonPath('code', 'cooldown');

    $this->travel(25)->hours();
    $this->postJson('/api/me/contact', ['channel' => 'mail', 'email' => 'new@example.com', 'current_password' => 'New-secret-2026'])->assertStatus(202);
});

it('signs in with a verified phone number', function () {
    Auth::guard('web')->logout();
    $this->postJson('/session/login', ['phone' => '01712345678', 'country_code' => 'BD', 'password' => 'password'])->assertOk();
    expect(Auth::guard('web')->id())->toBe($this->user->id);

    Auth::guard('web')->logout();
    $this->user->forceFill(['phone_verified_at' => null])->save();
    $this->postJson('/session/login', ['phone' => '01712345678', 'country_code' => 'BD', 'password' => 'password'])
        ->assertJsonValidationErrors(['phone' => __('tenancy.errors.credentials_phone')]);
});
