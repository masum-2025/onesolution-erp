<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Invitations\Models\Invitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/*
 * INV-1: owners and HR give staff a login without the staff signing up.
 * Invited from the members page (with roles) or from an HRM employee in one
 * step (invited, given roles, linked to the employee); a new account sets
 * its password from the link and is in the company; an existing account is
 * just added; links are sent again when they run out; who may, how many,
 * and other companies.
 */

beforeEach(function () {
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    toggles()->enable($this->w->g1, 'hrm', 'Test setup');
    toggles()->enable($this->w->g2, 'hrm', 'Test setup');
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->members = fn (string $path = '', $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id.'/members'.($path === '' ? '' : "/{$path}");
    $this->hrm = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/hrm/{$path}";
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);
    $this->staffRole = makeRole($this->w->c1, ['hrm.view'], 'Front desk');
});

/** Forget the session a test browser got (e.g. from setting a password), so bearer tokens speak again. */
function signOutBrowser(object $test): void
{
    $test->flushSession();
    app('auth')->forgetGuards();
}

function staffInviteLink(string $email): ?string
{
    $sent = Mail::mailer()->getSymfonyTransport()->messages()->last(fn ($message) => $message->getEnvelope()->getRecipients()[0]->getAddress() === $email);
    if ($sent === null || preg_match('#/invite/([A-Za-z0-9]{48})#', $sent->getOriginalMessage()->getTextBody(), $match) !== 1) {
        return null;
    }

    return $match[1];
}

it('invites someone new from the members page: no sign-up, a link to set a password, roles given', function () {
    $made = ($this->as)()->postJson(($this->members)('invite'), [
        'email' => 'Rina@Example.com', 'name' => 'Rina Akter', 'membership_type' => 'staff', 'role_ids' => [$this->staffRole->id],
    ])->assertCreated();
    expect($made->json('meta.invited'))->toBeTrue()
        ->and($made->json('data.roles.0.name'))->toBe('Front desk')
        ->and($made->json('data.invitation.expired'))->toBeFalse();
    $listed = collect(($this->as)()->getJson(($this->members)())->json('data'))->firstWhere('user.email', 'rina@example.com');
    expect($listed['invitation'])->not->toBeNull();

    $link = staffInviteLink('rina@example.com');
    expect($link)->not->toBeNull();
    $this->withHeader('Origin', config('app.url'))->postJson("/session/invitations/{$link}", ['password' => 'Rina2026!x', 'password_confirmation' => 'Rina2026!x'])->assertOk()
        ->assertJsonPath('contexts.organizations.0.name', $this->w->c1->displayName());
    expect(Hash::check('Rina2026!x', User::where('email', 'rina@example.com')->sole()->password))->toBeTrue();
    // Back to the owner (setting the password signed Rina in on this test browser).
    signOutBrowser($this);
    // In now: nothing pending, nothing to send again.
    $membership = collect(($this->as)()->getJson(($this->members)())->json('data'))->firstWhere('user.email', 'rina@example.com');
    expect($membership['invitation'])->toBeNull();
    ($this->as)()->postJson(($this->members)("{$membership['id']}/resend-invite"))->assertUnprocessable();
});

it('adds someone who has an account already, and sends a fresh link to whoever never set a password', function () {
    $known = User::factory()->create(['email' => 'known@example.com']);
    ($this->as)()->postJson(($this->members)('invite'), ['email' => 'known@example.com', 'name' => 'Known', 'membership_type' => 'staff'])
        ->assertCreated()->assertJsonPath('meta.invited', false)->assertJsonPath('data.invitation', null);
    expect(Invitation::query()->where('user_id', $known->id)->exists())->toBeFalse();
    ($this->as)()->postJson(($this->members)('invite'), ['email' => 'known@example.com', 'name' => 'Known', 'membership_type' => 'staff'])->assertUnprocessable();

    $made = ($this->as)()->postJson(($this->members)('invite'), ['email' => 'late@example.com', 'name' => 'Late', 'membership_type' => 'staff'])->assertCreated()->json('data');
    $first = staffInviteLink('late@example.com');
    // The link ran out (72 hours).
    Invitation::query()->update(['expires_at' => now()->subHour()]);
    expect(collect(($this->as)()->getJson(($this->members)())->json('data'))->firstWhere('id', $made['id'])['invitation']['expired'])->toBeTrue();
    $again = ($this->as)()->postJson(($this->members)("{$made['id']}/resend-invite"))->assertOk()->json('data');
    expect($again['invitation']['expired'])->toBeFalse();
    $second = staffInviteLink('late@example.com');
    expect($second)->not->toBe($first);
    $this->getJson("/session/invitations/{$first}")->assertNotFound();
    $this->getJson("/session/invitations/{$second}")->assertOk();
});

it('gives an employee a login from HRM in one step: invited, roles, linked', function () {
    $employee = hireVia($this, $this->token, $this->w->c1, ['full_name' => 'Kamal Hossain', 'email' => 'kamal@example.com'])->assertCreated()->json('data');
    $nomail = hireVia($this, $this->token, $this->w->c1, ['full_name' => 'Nasrin'])->assertCreated()->json('data');

    // A stale page sends nothing.
    ($this->as)()->postJson(($this->hrm)("employees/{$employee['id']}/invite-login"), ['base_version' => 99])->assertStatus(409);
    expect(Invitation::query()->count())->toBe(0);
    ($this->as)()->postJson(($this->hrm)("employees/{$nomail['id']}/invite-login"), ['base_version' => $nomail['version']])
        ->assertUnprocessable()->assertJsonValidationErrors('email');

    $linked = ($this->as)()->postJson(($this->hrm)("employees/{$employee['id']}/invite-login"), ['base_version' => $employee['version'], 'role_ids' => [$this->staffRole->id]])
        ->assertOk()->assertJsonPath('meta.invited', true)->json('data');
    $user = User::where('email', 'kamal@example.com')->sole();
    expect($linked['user_id'])->toBe($user->id)->and($linked['login_invitation']['expired'])->toBeFalse()
        ->and(DB::table('membership_roles')->where('role_id', $this->staffRole->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'hrm.login_linked')->count())->toBe(1);
    ($this->as)()->postJson(($this->hrm)("employees/{$employee['id']}/invite-login"), ['base_version' => $linked['version']])
        ->assertUnprocessable()->assertJsonValidationErrors('email');

    // The employee sets a password from the link and is in, as that employee.
    $link = staffInviteLink('kamal@example.com');
    $this->withHeader('Origin', config('app.url'))->postJson("/session/invitations/{$link}", ['password' => 'Kamal2026!x', 'password_confirmation' => 'Kamal2026!x'])->assertOk();
    signOutBrowser($this);
    ($this->as)()->getJson(($this->hrm)("employees/{$employee['id']}"))->assertJsonPath('data.login_invitation', null);
    ($this->as)()->postJson(($this->hrm)("employees/{$employee['id']}/resend-login"))->assertUnprocessable();
});

it('needs both HR and members rights, keeps to the company, and limits how many go', function () {
    $employee = hireVia($this, $this->token, $this->w->c1, ['full_name' => 'Kamal Hossain', 'email' => 'kamal@example.com'])->assertCreated()->json('data');
    $hrOnly = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'hrm.manage'], 'HR only')), $this->w->c1);
    ($this->as)($hrOnly)->postJson(($this->hrm)("employees/{$employee['id']}/invite-login"), ['base_version' => $employee['version']])->assertForbidden();
    ($this->as)($hrOnly)->postJson(($this->members)('invite'), ['email' => 'x@example.com', 'name' => 'Xavier', 'membership_type' => 'staff'])->assertForbidden();

    $other = orgToken(createMember($this->w->c2), $this->w->c2);
    ($this->as)($other)->postJson(($this->hrm)("employees/{$employee['id']}/invite-login", $this->w->c2), ['base_version' => $employee['version']])->assertNotFound();

    // Every try counts, refused ones too: this, then two, then the fourth is too many.
    config(['tenancy.throttle.invites_per_minute' => 3]);
    ($this->as)()->postJson(($this->members)('invite'), ['email' => 'x@example.com', 'name' => 'Xavier', 'membership_type' => 'staff', 'password' => 'x'])->assertUnprocessable();
    ($this->as)()->postJson(($this->members)('invite'), ['email' => 'a1@example.com', 'name' => 'A1', 'membership_type' => 'staff'])->assertCreated();
    ($this->as)()->postJson(($this->members)('invite'), ['email' => 'a2@example.com', 'name' => 'A2', 'membership_type' => 'staff'])->assertCreated();
    ($this->as)()->postJson(($this->members)('invite'), ['email' => 'a3@example.com', 'name' => 'A3', 'membership_type' => 'staff'])->assertTooManyRequests();
});
