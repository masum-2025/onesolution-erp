<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Billing\Models\Invoice;
use App\Platform\Identity\Events\WorkspaceErased;
use App\Platform\Identity\Models\UserSession;
use App\Platform\Identity\Services\PersonalWorkspaces;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Payments\Models\Payment;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * "Download my data" and "delete my account" (Phase 5C-3): a grace period
 * the person can cancel, then their personal data is erased while records a
 * B2B client owns stay with that client.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->world = selfServeWorld();
    fakeSslCommerz();
    $this->withHeader('Origin', config('app.url'));
});

function me(object $world): TestCase
{
    return test()->asToken(orgToken($world->user, $world->workspace));
}

function askDeletion(object $world, array $overrides = []): TestResponse
{
    return me($world)->postJson('/api/me/deletion', ['current_password' => 'password', 'confirm' => 'DELETE', ...$overrides]);
}

it('downloads the person\'s own data as a file', function () {
    $response = me($this->world)->get('/api/me/data')->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('attachment; filename="my-data-2026-10-05.json"');
    $data = json_decode($response->getContent(), true);

    expect($data['account']['email'])->toBe($this->world->user->email)
        ->and($data['memberships'][0]['organization_type'])->toBe('personal')
        ->and($data['memberships'][0]['membership_type'])->toBe('owner')
        ->and(AuditLog::query()->where('action', 'identity.data_downloaded')->exists())->toBeTrue();
});

it('limits downloads of my data', function () {
    foreach (range(1, 5) as $ignored) {
        me($this->world)->get('/api/me/data')->assertOk();
    }

    me($this->world)->get('/api/me/data')->assertStatus(429);
});

it('schedules the deletion after the grace period, tells every address, and can be cancelled', function () {
    $this->world->user->forceFill(['phone' => '+8801712345678', 'phone_verified_at' => now()])->save();
    partnerRule($this->world->partner, 'notifications.sms_enabled', true);

    askDeletion($this->world)
        ->assertOk()
        ->assertJsonPath('data.deletion_due_at', '2026-11-04T09:00:00+00:00')
        ->assertJsonPath('message', 'Your account will be deleted on 4 November 2026. You can cancel until then.');

    expect(NotificationDelivery::query()->where('notification_key', 'identity.deletion_requested')->pluck('channel')->sort()->values()->all())->toBe(['mail', 'sms'])
        ->and(AuditLog::query()->where('action', 'identity.deletion_requested')->exists())->toBeTrue();

    // The app shows it everywhere while it waits.
    me($this->world)->getJson('/api/me')->assertJsonPath('data.user.deletion_due_at', '2026-11-04T09:00:00+00:00');

    me($this->world)->deleteJson('/api/me/deletion')->assertOk()->assertJsonPath('data.deletion_due_at', null);
    expect($this->world->user->fresh()->deletion_due_at)->toBeNull()
        ->and(NotificationDelivery::query()->where('notification_key', 'identity.deletion_cancelled')->exists())->toBeTrue();

    me($this->world)->deleteJson('/api/me/deletion')->assertStatus(422)->assertJsonPath('code', 'no_deletion_pending');
});

it('needs the password and the typed word', function () {
    askDeletion($this->world, ['current_password' => 'wrong'])->assertStatus(422)->assertJsonPath('code', 'wrong_password');
    askDeletion($this->world, ['confirm' => 'yes'])->assertStatus(422)->assertJsonPath('code', 'deletion_confirm');

    expect($this->world->user->fresh()->deletion_due_at)->toBeNull();
});

it('never leaves something without an owner: the only owner of a team must hand it over first', function () {
    $w = tenancyWorld();
    $owner = createMember($w->c1, MembershipType::Owner);
    createMember($w->c1, MembershipType::Staff);
    $world = (object) ['user' => $owner, 'workspace' => $w->c1];

    askDeletion($world)
        ->assertStatus(409)
        ->assertJsonPath('code', 'deletion_blocked')
        ->assertJsonPath('blockers.0.code', 'only_owner')
        ->assertJsonPath('blockers.0.name', 'C1');

    // Another owner takes over: now it can go.
    createMember($w->c1, MembershipType::Owner);
    askDeletion($world)->assertOk();
});

it('blocks the only owner of a partner account', function () {
    $staff = createPartnerStaff($this->world->partner, PartnerUserRole::Owner);
    $workspace = app(PersonalWorkspaces::class)->create($staff, $this->world->partner, 'BD', 'en');

    askDeletion((object) ['user' => $staff, 'workspace' => $workspace])
        ->assertStatus(409)
        ->assertJsonPath('blockers.0.code', 'partner_owner');
});

it('erases the person after the grace period, closing only what was theirs alone', function () {
    Event::fake([WorkspaceErased::class]);
    $user = $this->world->user;
    $email = $user->email;

    // Also an employee of a B2B client, whose records stay with the client.
    $w = tenancyWorld();
    app(AddMember::class)->handle($w->c1, $user, MembershipType::Staff, AccessScope::Own);
    $colleague = createMember($w->c1, MembershipType::Owner);

    // A paid personal plan with an unpaid renewal.
    $payment = Payment::query()->findOrFail(startCheckout($this, $this->world)->json('data.id'));
    $this->post('/payments/sslcommerz/notify', sslNotice($payment))->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'));
    $this->artisan('billing:self-serve')->assertSuccessful();

    askDeletion($this->world)->assertOk();

    // Not before the date.
    $this->artisan('privacy:erase-due')->assertSuccessful();
    expect($user->fresh()->erased_at)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-11-30 04:00:00', 'UTC'));
    $this->artisan('privacy:erase-due')->assertSuccessful();

    $user->refresh();
    expect($user->erased_at)->not->toBeNull()
        ->and($user->name)->toBe('Deleted person')
        ->and($user->email)->toBeNull()
        ->and($user->phone)->toBeNull()
        ->and(UserSession::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($user->tokens()->count())->toBe(0);

    // Their own workspace: closed, name removed, unpaid invoice cancelled, invoices kept.
    $workspace = $this->world->workspace->fresh();
    expect($workspace->status)->toBe(OrganizationStatus::Archived)
        ->and($workspace->texts('name')['en'])->toBe('Deleted workspace')
        ->and(Invoice::query()->where('billing_key', 'like', 'renewal:%')->sole()->status)->toBe(Invoice::CREDITED)
        ->and(Invoice::query()->where('organization_id', $workspace->id)->where('type', Invoice::INVOICE)->count())->toBe(2);
    Event::assertDispatched(WorkspaceErased::class, fn (WorkspaceErased $event) => $event->organization->is($workspace));

    // The client's organization and its people are untouched; the membership ended.
    expect($w->c1->fresh()->status)->toBe(OrganizationStatus::Active)
        ->and(OrganizationMembership::query()->where('organization_id', $w->c1->id)->where('user_id', $user->id)->first()->status)->toBe(MembershipStatus::Suspended)
        ->and(OrganizationMembership::query()->where('organization_id', $w->c1->id)->where('user_id', $colleague->id)->first()->status)->toBe(MembershipStatus::Active)
        ->and(AuditLog::query()->where('action', 'identity.account_erased')->where('target_id', $user->id)->exists())->toBeTrue()
        // Audit entries keep pointing at the (now anonymous) person.
        ->and(AuditLog::query()->where('actor_user_id', $user->id)->where('action', 'identity.deletion_requested')->exists())->toBeTrue();

    // Nobody can sign in with the old address any more.
    $this->postJson('/session/login', ['email' => $email, 'password' => 'password'])->assertStatus(422);
});

it('waits when something became theirs alone during the grace period', function () {
    askDeletion($this->world)->assertOk();

    // The workspace was upgraded meanwhile and took a member: it would be left without owner.
    $this->world->workspace->forceFill(['plan_key' => 'starter']);
    DB::table('organizations')->where('id', $this->world->workspace->id)->update(['type' => 'company']);
    createMember($this->world->workspace->fresh(), MembershipType::Staff);

    $this->travel(31)->days();
    $this->artisan('privacy:erase-due')->expectsOutputToContain('0 erased, 1 waiting')->assertSuccessful();

    expect($this->world->user->fresh()->erased_at)->toBeNull();
});

it('shows on My account what would stop a deletion', function () {
    $w = tenancyWorld();
    $owner = createMember($w->c1, MembershipType::Owner);
    createMember($w->c1, MembershipType::Staff);

    test()->asToken(orgToken($owner, $w->c1))->getJson('/api/me/account')
        ->assertOk()
        ->assertJsonPath('data.deletion_due_at', null)
        ->assertJsonPath('data.deletion_blockers.0.code', 'only_owner')
        ->assertJsonPath('data.deletion_blockers.0.message', 'You are the only owner of C1, which has other members. Make one of them an owner first.');
});

it('only ever acts on the signed-in person\'s own deletion', function () {
    askDeletion($this->world)->assertOk();
    $other = User::factory()->create();

    // Another signed-in person cannot cancel or read someone else's deletion: there is no id to aim at.
    test()->actingAs($other)->deleteJson('/api/me/deletion')->assertStatus(422)->assertJsonPath('code', 'no_deletion_pending');
    expect($this->world->user->fresh()->deletion_due_at)->not->toBeNull();
});
