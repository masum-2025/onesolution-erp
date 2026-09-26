<?php

use App\Models\User;
use App\Platform\Invitations\Models\Invitation;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/*
 * Phase 5B-5: people added by email who have no account get a one-time link
 * to set their password; people with an account are just told.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->partnerOwner = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
});

function newClientFor(object $test, array $overrides = [])
{
    return $test->asToken($test->partnerOwner)->postJson('http://localhost/api/partner/clients', [
        'name' => ['en' => 'Sunrise School'],
        'sector_key' => 'school',
        'plan' => 'business',
        'owner_email' => 'head@sunrise.test',
        'owner_name' => 'Head Teacher',
        'country_code' => 'BD',
        ...$overrides,
    ]);
}

function invitationLink(string $email): string
{
    $sent = Mail::mailer()->getSymfonyTransport()->messages()->first(fn ($message) => $message->getEnvelope()->getRecipients()[0]->getAddress() === $email);
    preg_match('#/invite/([A-Za-z0-9]{48})#', $sent->getOriginalMessage()->getTextBody(), $match);

    return $match[1];
}

it('invites a new client owner by email to set a password', function () {
    newClientFor($this)->assertCreated()->assertJsonPath('owner_invited', true);

    $user = User::where('email', 'head@sunrise.test')->sole();
    $token = invitationLink('head@sunrise.test');
    expect(Invitation::sole()->token_hash)->toBe(hash('sha256', $token))
        ->and(Invitation::sole()->token_hash)->not->toBe($token);

    $this->getJson("/session/invitations/{$token}")->assertOk()
        ->assertJsonPath('data.name', 'Head Teacher')
        ->assertJsonPath('data.email', 'h***@sunrise.test')
        ->assertJsonPath('data.organization', 'Sunrise School');

    $this->withHeader('Origin', config('app.url'))->postJson("/session/invitations/{$token}", ['password' => 'Sunrise2026', 'password_confirmation' => 'Sunrise2026'])->assertOk()
        ->assertJsonPath('contexts.organizations.0.name', 'Sunrise School');

    expect(Hash::check('Sunrise2026', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->email_verified_at)->not->toBeNull();

    // Used once.
    $this->getJson("/session/invitations/{$token}")->assertNotFound()->assertJsonPath('code', 'invalid_invitation');
});

it('refuses expired links and weak passwords', function () {
    newClientFor($this);
    $token = invitationLink('head@sunrise.test');

    $this->withHeader('Origin', config('app.url'))->postJson("/session/invitations/{$token}", ['password' => 'short', 'password_confirmation' => 'short'])
        ->assertUnprocessable()->assertJsonValidationErrors('password');

    $this->travel(73)->hours();
    $this->getJson("/session/invitations/{$token}")->assertNotFound();
});

it('only tells people who already have an account', function () {
    $existing = User::factory()->create(['email' => 'head@sunrise.test']);

    newClientFor($this)->assertCreated()->assertJsonPath('owner_invited', false);

    expect(Invitation::count())->toBe(0);
    $sent = Mail::mailer()->getSymfonyTransport()->messages()->first(fn ($message) => $message->getEnvelope()->getRecipients()[0]->getAddress() === $existing->email);
    expect($sent->getOriginalMessage()->getSubject())->toBe('You now have access to Sunrise School');
});

it('asks for a name before inviting someone new', function () {
    newClientFor($this, ['owner_name' => null])->assertUnprocessable()->assertJsonValidationErrors('owner_email');
    expect(User::where('email', 'head@sunrise.test')->exists())->toBeFalse();
});
