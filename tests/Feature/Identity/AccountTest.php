<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Identity\Jobs\SendOtp;
use App\Platform\Identity\Models\UserSession;
use App\Platform\Identity\Services\OtpService;
use App\Platform\Identity\Services\PersonalWorkspaces;
use App\Platform\Notifications\Contracts\SmsGateway;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Notifications\Services\LogSmsGateway;
use App\Platform\Packaging\Models\OrganizationPackage;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;

/*
 * My account (Phase 5C-1): the signed-in person's own identity.
 */

beforeEach(function () {
    $this->house = Partner::factory()->house()->create();
    partnerRule($this->house, 'notifications.sms_enabled', true);
    app()->instance(SmsGateway::class, new LogSmsGateway);
    Bus::fake([SendOtp::class]);

    $this->user = User::factory()->create(['email' => 'rahim@example.com']);
    $this->workspace = app(PersonalWorkspaces::class)->create($this->user, $this->house, 'BD', 'en');
    spaSession($this, $this->user);
    // Like a browser: keep sending the session cookie the sign-in set.
    $this->withCredentials()->withCookie(config('session.cookie'), app('session.store')->getId());
});

function accountCode(): string
{
    return Bus::dispatched(SendOtp::class)->last()->code;
}

it('shows and updates the profile, language and marketing consent', function () {
    $this->getJson('/api/me/account')
        ->assertOk()
        ->assertJsonPath('data.email', 'rahim@example.com')
        ->assertJsonPath('data.onboarded', false)
        ->assertJsonPath('data.options.phone', true);

    $this->patchJson('/api/me/account', ['name' => 'Rahim Uddin', 'locale' => 'bn', 'marketing' => true])
        ->assertOk()
        ->assertJsonPath('data.name', 'Rahim Uddin')
        ->assertJsonPath('data.marketing', true);

    $this->patchJson('/api/me/account', ['email' => 'x@example.com'])->assertJsonValidationErrors('email');
    expect(AuditLog::where('action', 'identity.profile_updated')->count())->toBe(1);
});

it('changes the password only with the current one, and signs out everywhere else', function () {
    UserSession::query()->forceCreate(['user_id' => $this->user->id, 'session_hash' => UserSession::hashOf('phone-browser'), 'last_seen_at' => now()]);

    $this->putJson('/api/me/password', ['current_password' => 'wrong', 'password' => 'New-secret-2026', 'password_confirmation' => 'New-secret-2026'])
        ->assertJsonPath('code', 'wrong_password');

    $this->putJson('/api/me/password', ['current_password' => 'password', 'password' => 'New-secret-2026', 'password_confirmation' => 'New-secret-2026'])->assertOk();

    expect(Hash::check('New-secret-2026', $this->user->fresh()->password))->toBeTrue()
        ->and(UserSession::where('session_hash', UserSession::hashOf('phone-browser'))->value('revoked_at'))->not->toBeNull()
        ->and(NotificationDelivery::where('notification_key', 'identity.password_changed')->exists())->toBeTrue();

    // This browser stays signed in.
    $this->getJson('/api/me/account')->assertOk();
});

it('adds a phone with a code sent to the new number, and tells the old address about changes', function () {
    $start = $this->postJson('/api/me/contact', ['channel' => 'sms', 'phone' => '01812345678', 'country_code' => 'BD', 'current_password' => 'password'])->assertStatus(202);
    expect(Bus::dispatched(SendOtp::class)->last()->to)->toBe('+8801812345678');
    expect($this->user->fresh()->phone)->toBeNull();

    $this->postJson('/api/me/contact/verify', ['challenge_id' => $start->json('data.challenge_id'), 'code' => accountCode()])
        ->assertOk()->assertJsonPath('data.phone', '+8801812345678')->assertJsonPath('data.phone_verified', true);

    // Changing the email: the old email is told.
    $start = $this->postJson('/api/me/contact', ['channel' => 'mail', 'email' => 'new@example.com', 'current_password' => 'password'])->assertStatus(202);
    $this->postJson('/api/me/contact/verify', ['challenge_id' => $start->json('data.challenge_id'), 'code' => accountCode()])->assertOk();

    expect($this->user->fresh()->email)->toBe('new@example.com')
        ->and(NotificationDelivery::where('notification_key', 'identity.contact_changed')->where('channel', 'mail')->value('recipient'))->toStartWith('r')
        ->and(AuditLog::where('action', 'identity.email_changed')->exists())->toBeTrue();
});

it('never lets a person take someone else\'s number or use another person\'s code', function () {
    $other = User::factory()->create();
    $other->forceFill(['phone' => '+8801912345678', 'phone_verified_at' => now()])->save();

    $start = $this->postJson('/api/me/contact', ['channel' => 'sms', 'phone' => '01912345678', 'country_code' => 'BD', 'current_password' => 'password'])->assertStatus(202);
    Bus::assertNotDispatched(SendOtp::class);
    $this->postJson('/api/me/contact/verify', ['challenge_id' => $start->json('data.challenge_id'), 'code' => '123456'])->assertUnprocessable();

    // A code made for another person's account change does not work here.
    $theirs = app(OtpService::class)->issue('verify_contact', 'mail', 'z@example.com', $this->house, $other);
    $this->postJson('/api/me/contact/verify', ['challenge_id' => $theirs->id, 'code' => accountCode()])->assertJsonPath('code', 'code_expired');
    expect($this->user->fresh()->email)->toBe('rahim@example.com');
});

it('keeps at least one way to sign in', function () {
    $this->user->forceFill(['phone' => '+8801812345678', 'phone_verified_at' => now()])->save();
    $this->deleteJson('/api/me/phone', ['current_password' => 'password'])->assertOk()->assertJsonPath('data.phone', null);

    $this->user->forceFill(['email' => null, 'phone' => '+8801812345678', 'phone_verified_at' => now()])->save();
    app('auth')->forgetGuards();
    $this->deleteJson('/api/me/phone', ['current_password' => 'password'])->assertJsonPath('code', 'last_sign_in_method');
});

it('lists signed-in devices and signs one out for good', function () {
    $this->getJson('/api/me/sessions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.current', true);

    UserSession::query()->forceCreate(['user_id' => $this->user->id, 'session_hash' => UserSession::hashOf('tablet'), 'browser' => 'Chrome', 'platform' => 'Android', 'last_seen_at' => now()->subHour()]);
    $sessions = $this->getJson('/api/me/sessions')->assertJsonCount(2, 'data')->json('data');
    $tablet = collect($sessions)->firstWhere('current', false);

    $this->deleteJson("/api/me/sessions/{$tablet['id']}")->assertOk();
    $this->deleteJson("/api/me/sessions/{$tablet['id']}")->assertNotFound();
    $this->getJson('/api/me/sessions')->assertJsonCount(1, 'data');

    // Someone else's device cannot be touched.
    $stranger = UserSession::query()->forceCreate(['user_id' => User::factory()->create()->id, 'session_hash' => UserSession::hashOf('x'), 'last_seen_at' => now()]);
    $this->deleteJson("/api/me/sessions/{$stranger->id}")->assertNotFound();
});

it('keeps one device when the session is renewed, e.g. on entering a workspace', function () {
    $this->postJson('/session/context', ['organization_id' => $this->workspace->id])->assertOk();

    expect(UserSession::where('user_id', $this->user->id)->count())->toBe(1)
        ->and(UserSession::where('user_id', $this->user->id)->value('session_hash'))->toBe(UserSession::hashOf(app('session.store')->getId()));
});

it('signs a browser out on its next request once its session was ended elsewhere', function () {
    $current = UserSession::where('user_id', $this->user->id)->sole();
    $current->forceFill(['revoked_at' => now()])->save();

    $this->getJson('/api/me/account')->assertUnauthorized();
    expect(Auth::guard('web')->check())->toBeFalse();
});

it('runs the first-run setup once: language, country and the kind of work', function () {
    $this->postJson('/api/me/onboarding', ['locale' => 'bn', 'country_code' => 'BD', 'sector_key' => 'school'])->assertOk()->assertJsonPath('data.onboarded', true);

    $workspace = $this->workspace->fresh();
    expect($this->user->fresh()->locale)->toBe('bn')
        ->and($workspace->default_locale)->toBe('bn')
        ->and($workspace->sector_key)->toBe('school')
        ->and(OrganizationPackage::where('organization_id', $workspace->id)->exists())->toBeTrue();

    // The sector is not changed again here.
    $this->postJson('/api/me/onboarding', ['locale' => 'en', 'sector_key' => 'general'])->assertOk();
    expect($workspace->fresh()->sector_key)->toBe('school');
});

it('needs a signed-in person', function () {
    Auth::guard('web')->logout();
    $this->getJson('/api/me/account')->assertUnauthorized();
    $this->putJson('/api/me/password', [])->assertUnauthorized();
});
