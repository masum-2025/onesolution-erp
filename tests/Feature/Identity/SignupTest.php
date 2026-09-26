<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Identity\Contracts\BotCheck;
use App\Platform\Identity\Events\UserSignedUp;
use App\Platform\Identity\Jobs\SendOtp;
use App\Platform\Legal\Models\DocumentAcceptance;
use App\Platform\Legal\Services\LegalService;
use App\Platform\Notifications\Contracts\SmsGateway;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Notifications\Services\LogSmsGateway;
use App\Platform\Packaging\Actions\ChangePlan;
use App\Platform\Packaging\Exceptions\PackagingException;
use App\Platform\Partners\Services\PartnerClientService;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Self-serve sign-up (Phase 5C-1): a code to the email or phone first, then
 * the account, the personal workspace and the terms acceptance at once.
 */

beforeEach(function () {
    $this->house = Partner::factory()->house()->create(['name' => 'One Solutions']);
    partnerRule($this->house, 'b2c.self_signup_allowed', true);
    partnerRule($this->house, 'notifications.sms_enabled', true);
    $this->gateway = new LogSmsGateway;
    app()->instance(SmsGateway::class, $this->gateway);
    $this->terms = app(LegalService::class)->current($this->house, 'terms');
    $this->withHeader('Origin', config('app.url'));
    RateLimiter::clear('identity-start:127.0.0.1');
});

function signupForm(array $overrides = []): array
{
    return [
        'channel' => 'sms',
        'phone' => '01712345678',
        'country_code' => 'BD',
        'name' => 'Rahima Akter',
        'password' => 'Secret-pass-2026',
        'password_confirmation' => 'Secret-pass-2026',
        'locale' => 'bn',
        'accept_terms' => true,
        'terms_version' => app(LegalService::class)->current(Partner::query()->where('is_house', true)->first(), 'terms')?->version,
        ...$overrides,
    ];
}

function lastOtp(): SendOtp
{
    return Bus::dispatched(SendOtp::class)->last();
}

it('signs a person up by phone: code, account, personal workspace, terms and session', function () {
    Bus::fake([SendOtp::class]);
    Event::fake([UserSignedUp::class]);

    $start = $this->postJson('/session/signup', signupForm(['marketing' => true]))
        ->assertStatus(202)
        ->assertJsonPath('data.channel', 'sms')
        ->assertJsonPath('data.length', 6);

    // Nothing exists until the code is entered; the number is shown masked.
    expect(User::where('phone', '+8801712345678')->exists())->toBeFalse()
        ->and($start->json('data.to'))->not->toContain('12345678')
        ->and(lastOtp()->to)->toBe('+8801712345678')
        ->and(lastOtp()->channel)->toBe('sms');

    $this->postJson('/session/signup/verify', ['challenge_id' => $start->json('data.challenge_id'), 'code' => lastOtp()->code])
        ->assertOk()
        ->assertJsonPath('contexts.organizations.0.type', 'personal');

    $user = User::where('phone', '+8801712345678')->sole();
    $workspace = Organization::where('type', OrganizationType::Personal)->sole();

    expect($user->phone_verified_at)->not->toBeNull()
        ->and($user->email)->toBeNull()
        ->and($user->locale)->toBe('bn')
        ->and($user->marketing_consent_at)->not->toBeNull()
        ->and($workspace->partner_id)->toBe($this->house->id)
        ->and($workspace->parent_id)->toBeNull()
        ->and($workspace->plan_key)->toBe('personal_free')
        ->and($workspace->country_code)->toBe('BD')
        ->and($workspace->memberships()->sole()->membership_type)->toBe(MembershipType::Owner)
        ->and(DocumentAcceptance::where('organization_id', $workspace->id)->where('kind', 'terms')->sole()->version)->toBe($this->terms->version)
        ->and(Auth::guard('web')->id())->toBe($user->id)
        ->and(AuditLog::where('action', 'identity.signed_up')->where('actor_user_id', $user->id)->exists())->toBeTrue()
        // A person accepts the terms; the DPA is for businesses.
        ->and(app(LegalService::class)->pending($workspace))->toBe([]);

    Event::assertDispatched(UserSignedUp::class, fn ($event) => $event->user->is($user) && $event->channel === 'sms');
});

it('sends the code by email and SMS for real, never storing it', function () {
    $this->postJson('/session/signup', signupForm())->assertStatus(202);
    expect($this->gateway->sent)->toHaveCount(1)
        ->and($this->gateway->sent[0]['to'])->toBe('+8801712345678')
        ->and($this->gateway->sent[0]['text'])->toMatch('/\d{6}/');

    $this->postJson('/session/signup', signupForm(['channel' => 'mail', 'email' => 'rahima@example.com', 'phone' => null]))->assertStatus(202);
    $sent = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    expect($sent->getTo()[0]->getAddress())->toBe('rahima@example.com')
        ->and($sent->getSubject())->toMatch('/\d{6}/');

    // Nothing readable anywhere: no code in the challenge row, no delivery record.
    $row = DB::table('otp_challenges')->latest('created_at')->first();
    expect(json_encode($row))->not->toContain('rahima@example.com')
        ->and(NotificationDelivery::count())->toBe(0);
});

it('counts wrong codes down, then closes the challenge', function () {
    Bus::fake([SendOtp::class]);
    $id = $this->postJson('/session/signup', signupForm())->json('data.challenge_id');
    $wrong = lastOtp()->code === '000000' ? '111111' : '000000';

    $this->postJson('/session/signup/verify', ['challenge_id' => $id, 'code' => $wrong])
        ->assertUnprocessable()->assertJsonPath('attempts_left', 4);
    foreach (range(1, 3) as $try) {
        $this->postJson('/session/signup/verify', ['challenge_id' => $id, 'code' => $wrong])->assertUnprocessable();
    }
    $this->postJson('/session/signup/verify', ['challenge_id' => $id, 'code' => $wrong])->assertJsonPath('code', 'code_expired');

    // Even the right code no longer works.
    $this->postJson('/session/signup/verify', ['challenge_id' => $id, 'code' => lastOtp()->code])->assertJsonPath('code', 'code_expired');
    expect(User::where('phone', '+8801712345678')->exists())->toBeFalse();
});

it('waits before a new code, and the new code replaces the old one', function () {
    Bus::fake([SendOtp::class]);
    $id = $this->postJson('/session/signup', signupForm())->json('data.challenge_id');
    $first = lastOtp()->code;

    $this->postJson('/session/otp/resend', ['challenge_id' => $id])->assertStatus(429)->assertJsonPath('code', 'resend_too_soon');

    $this->travel(61)->seconds();
    $this->postJson('/session/otp/resend', ['challenge_id' => $id])->assertOk();
    $second = lastOtp()->code;
    Bus::assertDispatchedTimes(SendOtp::class, 2);

    if ($first !== $second) {
        $this->postJson('/session/signup/verify', ['challenge_id' => $id, 'code' => $first])->assertJsonPath('code', 'code_wrong');
    }
    $this->postJson('/session/signup/verify', ['challenge_id' => $id, 'code' => $second])->assertOk();
});

it('answers the same for a taken address, sends no code and tells the owner', function () {
    Bus::fake([SendOtp::class]);
    $owner = User::factory()->create(['email' => 'taken@example.com']);

    $taken = $this->postJson('/session/signup', signupForm(['channel' => 'mail', 'email' => 'taken@example.com', 'phone' => null]))->assertStatus(202);
    $fresh = $this->postJson('/session/signup', signupForm(['channel' => 'mail', 'email' => 'fresh@example.com', 'phone' => null]))->assertStatus(202);

    expect(array_keys($taken->json('data')))->toBe(array_keys($fresh->json('data')));
    Bus::assertDispatchedTimes(SendOtp::class, 1);
    expect(NotificationDelivery::where('notification_key', 'identity.signup_attempt')->where('user_id', $owner->id)->exists())->toBeTrue();

    // No code can pass a decoy.
    foreach (['000000', '123456'] as $guess) {
        $this->postJson('/session/signup/verify', ['challenge_id' => $taken->json('data.challenge_id'), 'code' => $guess])->assertUnprocessable();
    }
    expect(User::where('email', 'taken@example.com')->count())->toBe(1);
});

it('validates the form clearly', function (array $overrides, string $field) {
    $this->postJson('/session/signup', signupForm($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no terms' => [['accept_terms' => false], 'accept_terms'],
    'weak password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'unknown field' => [['organization_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'], 'organization_id'],
    'no phone' => [['phone' => null], 'phone'],
]);

it('refuses throwaway emails, wrong numbers and numbers from other countries', function () {
    $this->postJson('/session/signup', signupForm(['channel' => 'mail', 'email' => 'x@mailinator.com', 'phone' => null]))->assertJsonPath('code', 'disposable_email');
    $this->postJson('/session/signup', signupForm(['phone' => '0123']))->assertJsonPath('code', 'bad_phone');
    $this->postJson('/session/signup', signupForm(['phone' => '+919812345678']))->assertJsonPath('code', 'phone_country_not_allowed');

    // The partner can allow throwaway inboxes.
    partnerRule($this->house, 'identity.block_disposable_email', false);
    $this->postJson('/session/signup', signupForm(['channel' => 'mail', 'email' => 'x@mailinator.com', 'phone' => null]))->assertStatus(202);
});

it('offers phone only where the partner sends SMS', function () {
    partnerRule($this->house, 'notifications.sms_enabled', false);

    $this->getJson('/session/signup/options')->assertJsonPath('data.channels', ['mail'])->assertJsonPath('data.phone', false);
    $this->postJson('/session/signup', signupForm())->assertJsonPath('code', 'sms_unavailable');
});

it('needs the bot check to pass', function () {
    app()->instance(BotCheck::class, new class implements BotCheck
    {
        public function passes(?string $token, ?string $ip): bool
        {
            return $token === 'human';
        }

        public function publicConfig(): array
        {
            return ['driver' => 'test', 'site_key' => 'site'];
        }

        public function origins(): array
        {
            return [];
        }
    });

    $this->postJson('/session/signup', signupForm())->assertUnprocessable()->assertJsonPath('code', 'bot_check_failed');
    $this->postJson('/session/signup', signupForm(['bot_token' => 'human']))->assertStatus(202);
});

it('limits codes per number per hour and bursts per network', function () {
    Bus::fake([SendOtp::class]);
    partnerRule($this->house, 'identity.otp_per_hour_per_destination', 2);

    $this->postJson('/session/signup', signupForm())->assertStatus(202);
    $this->postJson('/session/signup', signupForm())->assertStatus(202);
    $this->postJson('/session/signup', signupForm())->assertStatus(429)->assertJsonPath('code', 'too_many_codes');

    // A few starts a minute from one network, whatever the number.
    foreach (range(1, 3) as $n) {
        $this->postJson('/session/signup', signupForm(['phone' => "0171234560{$n}"]));
    }
    $this->postJson('/session/signup', signupForm(['phone' => '01712345699']))->assertStatus(429);
});

it('is closed where the partner does not take sign-ups, and on a client\'s own address', function () {
    partnerRule($this->house, 'b2c.self_signup_allowed', false);
    $this->getJson('/session/signup/options')->assertJsonPath('data.allowed', false);
    $this->postJson('/session/signup', signupForm())->assertForbidden()->assertJsonPath('code', 'signup_closed');

    // A white-label partner that takes sign-ups gets them under its own brand and tree.
    $acme = Partner::factory()->create(['name' => 'Acme']);
    activeDomain($acme, 'erp.acme.test');
    partnerRule($acme, 'b2c.self_signup_allowed', true);
    partnerRule($acme, 'notifications.sms_enabled', true);
    Bus::fake([SendOtp::class]);

    $id = $this->postJson('http://erp.acme.test/session/signup', signupForm(['terms_version' => null]))->assertStatus(202)->json('data.challenge_id');
    $this->postJson('http://erp.acme.test/session/signup/verify', ['challenge_id' => $id, 'code' => lastOtp()->code])->assertOk();
    expect(Organization::where('type', OrganizationType::Personal)->sole()->partner_id)->toBe($acme->id);

    // Its client's own address never takes sign-ups.
    $client = createGroup($acme, 'Sunrise');
    activeDomain($acme, 'sunrise.acme.test', $client);
    $this->postJson('http://sunrise.acme.test/session/signup', signupForm(['phone' => '01812345678']))->assertForbidden();
});

it('keeps a person\'s personal workspace and their employer apart', function () {
    Bus::fake([SendOtp::class]);
    $w = tenancyWorld();
    $employee = createMember($w->c1);
    $employee->forceFill(['phone' => '+8801912345678', 'phone_verified_at' => now()])->save();

    // Sign-up with the same number is a decoy: one identity, never two.
    $this->postJson('/session/signup', signupForm(['phone' => '01912345678']))->assertStatus(202);
    Bus::assertNotDispatched(SendOtp::class);

    // The same person with their own workspace (made through sign-up by email).
    $id = $this->postJson('/session/signup', signupForm(['channel' => 'mail', 'email' => 'me@example.com', 'phone' => null]))->json('data.challenge_id');
    $this->postJson('/session/signup/verify', ['challenge_id' => $id, 'code' => lastOtp()->code])->assertOk();
    $person = User::where('email', 'me@example.com')->sole();
    $workspace = Organization::where('type', OrganizationType::Personal)->sole();
    addMemberTo($w->c1, $person);
    // From here on an API client with tokens, not the browser session.
    Auth::guard('web')->logout();

    // From the personal workspace, the employer's organizations are out of reach, and the other way round.
    $this->asToken(orgToken($person, $workspace))->getJson("/api/organizations/{$w->c1->id}")->assertNotFound();
    $this->asToken(orgToken($person, $w->c1))->getJson("/api/organizations/{$workspace->id}")->assertNotFound();
});

it('gives personal workspaces personal plans only, and business clients business plans only', function () {
    Bus::fake([SendOtp::class]);
    $id = $this->postJson('/session/signup', signupForm())->json('data.challenge_id');
    $this->postJson('/session/signup/verify', ['challenge_id' => $id, 'code' => lastOtp()->code])->assertOk();
    $workspace = Organization::where('type', OrganizationType::Personal)->sole();
    $person = User::where('phone', '+8801712345678')->sole();

    expect(fn () => app(ChangePlan::class)->handle($workspace, 'business', 'Test', $person))
        ->toThrow(PackagingException::class, 'wrong_audience_personal');
    app(ChangePlan::class)->handle($workspace, 'personal_plus', 'Upgrade', $person);
    expect($workspace->fresh()->plan_key)->toBe('personal_plus');

    Auth::guard('web')->logout();
    $w = tenancyWorld();
    expect(fn () => app(ChangePlan::class)->handle($w->g1, 'personal_plus', 'Test', $person))->toThrow(PackagingException::class, 'wrong_audience_business');

    // Personal plans are not on offer to business clients, and the catalog keeps them apart.
    $partnerOwner = createPartnerStaff($this->house, \App\Platform\Tenancy\Enums\PartnerUserRole::Owner);
    $this->asToken(partnerToken($partnerOwner, $this->house))
        ->postJson('/api/partner/clients', ['name' => ['en' => 'Shop'], 'sector_key' => 'general', 'plan' => 'personal_free', 'owner_email' => $partnerOwner->email])
        ->assertJsonValidationErrors('plan');
    $this->asToken(orgToken($person, $workspace))->getJson('/api/plans?audience=personal')->assertJsonPath('data.0.key', 'personal_free');
    expect(collect($this->asToken(orgToken($person, $workspace))->getJson('/api/plans')->json('data'))->pluck('key'))->not->toContain('personal_free');

    // Personal workspaces do not use up the partner's client slots.
    partnerRule($this->house, 'partners.max_clients', 1);
    app(PartnerClientService::class)->assertClientSlot($this->house);
});

function addMemberTo(Organization $organization, User $user): void
{
    app(\App\Platform\Tenancy\Actions\AddMember::class)->handle($organization, $user, MembershipType::Staff);
}
