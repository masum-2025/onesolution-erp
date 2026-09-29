<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Billing\Models\Invoice;
use App\Platform\Billing\Services\BillingRun;
use App\Platform\Modules\Events\ModuleDisabled;
use App\Platform\Packaging\Actions\ChangePlan;
use App\Platform\Packaging\Actions\PlanChoice;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Packaging\Services\PartnerPlanService;
use App\Platform\Partners\Enums\DomainStatus;
use App\Platform\SupportAccess\Enums\Severity;
use App\Platform\SupportAccess\Services\SupportAccessService;
use App\Platform\Tenancy\Enums\BillingMode;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use App\Platform\Transfers\Models\ClientTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/*
 * Phase 5B-4: a client moves to another partner with all its data, on its
 * owner's decision and the new partner's acceptance; the old partner cannot
 * stop it; past invoices stay; nothing leaks between partners.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->clientOwner = createMember($this->w->g1);
    $this->ownerToken = orgToken($this->clientOwner, $this->w->g1);
    $this->ownerB = createPartnerStaff($this->w->partnerB, PartnerUserRole::Owner);
    $this->partnerBToken = partnerToken($this->ownerB, $this->w->partnerB);
});

function transferCode(object $test, ?string $token = null): string
{
    return $test->asToken($token ?? $test->partnerBToken)->postJson('http://localhost/api/partner/transfer-codes', ['label' => 'For G1'])
        ->assertCreated()->json('data.code');
}

function askToMove(object $test, array $body, ?string $token = null)
{
    return $test->asToken($token ?? $test->ownerToken)->postJson("http://localhost/api/organizations/{$test->w->g1->id}/transfer", [
        'reason' => 'Better local support',
        'consent' => true,
        ...$body,
    ]);
}

it('moves a client with all its data once the new partner accepts', function () {
    $code = transferCode($this);
    expect($code)->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/');

    $this->asToken($this->ownerToken)->postJson("http://localhost/api/organizations/{$this->w->g1->id}/transfer/preview", ['code' => strtolower($code)])
        ->assertOk()->assertJsonPath('data.to.name', 'Partner B')->assertJsonPath('data.problems', []);

    $transfer = askToMove($this, ['code' => $code])->assertCreated()->assertJsonPath('data.status', 'awaiting_partner')->json('data.id');
    // Nothing moved yet.
    expect($this->w->g1->fresh()->partner_id)->toBe($this->w->partnerA->id);

    $this->asToken($this->partnerBToken)->postJson("http://localhost/api/partner/transfers/{$transfer}/accept")->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('message', 'G1 is now your client.');

    // The whole tree moved; the tree itself did not change.
    foreach (['g1', 'c1', 'b1', 'd1', 'c2', 'b2'] as $unit) {
        expect($this->w->{$unit}->fresh()->partner_id)->toBe($this->w->partnerB->id);
    }
    expect($this->w->c1->fresh()->parent_id)->toBe($this->w->g1->id)
        ->and($this->w->g2->fresh()->partner_id)->toBe($this->w->partnerA->id)
        ->and($this->w->g1->memberships()->where('user_id', $this->clientOwner->id)->exists())->toBeTrue()
        ->and(Subscription::where('organization_id', $this->w->g1->id)->value('partner_id'))->toBe($this->w->partnerB->id);

    // In the client's log and in both partners' logs.
    $logs = AuditLog::where('action', 'client.transferred')->get();
    expect($logs->pluck('partner_id')->sort()->values()->all())->toBe(collect([$this->w->partnerA->id, $this->w->partnerB->id])->sort()->values()->all())
        ->and($logs->pluck('organization_id')->unique()->all())->toBe([$this->w->g1->id]);

    // The new partner now sees the client; the old one does not.
    $this->asToken($this->partnerBToken)->getJson("http://localhost/api/partner/organizations/{$this->w->g1->id}")->assertOk();
    $this->asToken(partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA))
        ->getJson("http://localhost/api/partner/organizations/{$this->w->g1->id}")->assertNotFound();
});

it('tells everyone, in each one\'s brand, without naming the new provider to the old one', function () {
    $ownerA = createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner);
    $code = transferCode($this);
    Mail::mailer()->getSymfonyTransport()->flush();

    $transfer = askToMove($this, ['code' => $code])->json('data.id');
    $sent = Mail::mailer()->getSymfonyTransport()->messages();
    expect($sent->first()->getOriginalMessage()->getSubject())->toBe('G1 wants to move to you');

    $this->asToken($this->partnerBToken)->postJson("http://localhost/api/partner/transfers/{$transfer}/accept")->assertOk();

    $subjects = Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($message) => [$message->getEnvelope()->getRecipients()[0]->getAddress(), $message->getOriginalMessage()->getSubject()]);
    expect($subjects)->toContain([$this->clientOwner->email, 'G1 is now with Partner B'])
        ->toContain([$ownerA->email, 'G1 has moved to another provider']);
});

it('lets only the account owner ask, and never with a bad, used or own code', function () {
    $code = transferCode($this);
    $companyOwner = createMember($this->w->c1);
    $staff = createMember($this->w->g1, MembershipType::Staff);

    // A company owner inside the account does not even see the account's top.
    askToMove($this, ['code' => $code], orgToken($companyOwner, $this->w->c1))->assertNotFound();
    askToMove($this, ['code' => $code], orgToken($staff, $this->w->g1))->assertForbidden();
    askToMove($this, ['code' => 'AAAA-BBBB-CCCC'])->assertUnprocessable()->assertJsonPath('code', 'invalid_code');
    askToMove($this, ['code' => $code, 'consent' => false])->assertUnprocessable()->assertJsonValidationErrors('consent');

    // A partner's own code cannot move its own client.
    $ownCode = transferCode($this, partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA));
    askToMove($this, ['code' => $ownCode])->assertUnprocessable()->assertJsonPath('code', 'same_partner');

    askToMove($this, ['code' => $code])->assertCreated();
    askToMove($this, ['code' => transferCode($this)])->assertUnprocessable()->assertJsonPath('code', 'already_open');

    // Used once: after cancelling, the same code does not work again.
    $open = ClientTransfer::sole();
    $this->asToken($this->ownerToken)->postJson("http://localhost/api/organizations/{$this->w->g1->id}/transfer/{$open->id}/cancel")->assertOk();
    askToMove($this, ['code' => $code])->assertUnprocessable()->assertJsonPath('code', 'invalid_code');
});

it('refuses expired codes', function () {
    $code = transferCode($this);
    $this->travel(15)->days();

    askToMove($this, ['code' => $code], orgToken($this->clientOwner, $this->w->g1))->assertUnprocessable()->assertJsonPath('code', 'invalid_code');
});

it('lets the new partner reject, and keeps other partners out', function () {
    $transfer = askToMove($this, ['code' => transferCode($this)])->json('data.id');
    $partnerC = Partner::factory()->create();
    $ownerC = partnerToken(createPartnerStaff($partnerC, PartnerUserRole::Owner), $partnerC);
    $salesB = partnerToken(createPartnerStaff($this->w->partnerB, PartnerUserRole::Sales), $this->w->partnerB);

    $this->asToken($ownerC)->postJson("http://localhost/api/partner/transfers/{$transfer}/accept")->assertNotFound();
    $this->asToken($salesB)->postJson("http://localhost/api/partner/transfers/{$transfer}/accept")->assertForbidden();
    $this->asToken($this->partnerBToken)->postJson("http://localhost/api/partner/transfers/{$transfer}/reject", [])->assertUnprocessable()->assertJsonValidationErrors('note');
    $this->asToken($this->partnerBToken)->postJson("http://localhost/api/partner/transfers/{$transfer}/reject", ['note' => 'We do not serve schools'])->assertOk();

    expect($this->w->g1->fresh()->partner_id)->toBe($this->w->partnerA->id);
    $this->asToken($this->partnerBToken)->postJson("http://localhost/api/partner/transfers/{$transfer}/accept")->assertUnprocessable()->assertJsonPath('code', 'not_open');
});

it('returns a client to the house partner at once', function () {
    Partner::factory()->house()->create(['name' => 'One Solutions']);

    askToMove($this, ['to_house' => true])->assertCreated()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('message', 'Your account is now with One Solutions.');
});

it('respects the new partner\'s governance', function () {
    partnerRule($this->w->partnerB, 'partners.allowed_countries', ['IN']);

    $code = transferCode($this);
    $this->asToken($this->ownerToken)->postJson("http://localhost/api/organizations/{$this->w->g1->id}/transfer/preview", ['code' => $code])
        ->assertOk()->assertJsonCount(1, 'data.problems');
    askToMove($this, ['code' => $code])->assertUnprocessable()->assertJsonPath('code', 'blocked');
});

it('ends what belonged to the old partner and keeps its past invoices there', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'UTC'));
    $this->w->partnerA->forceFill(['billing_mode' => BillingMode::RevenueShare])->save();
    setPlan($this->w->g1, 'business');
    $plan = app(PartnerPlanService::class)->create($this->w->partnerA, [
        'base_plan_key' => 'business',
        'name' => ['en' => 'School Plus'],
        'modules' => ['hrm', 'attendance', 'payroll', 'custom_reports'],
        'prices' => [['currency' => 'BDT', 'period' => 'monthly', 'amount_minor' => 700000]],
    ], createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner));
    app(ChangePlan::class)->handle($this->w->g1->fresh(), new PlanChoice('business', $plan), 'Offer', $this->clientOwner);
    app(BillingRun::class)->run(CarbonImmutable::parse('2026-10-01', 'UTC'), $this->w->partnerA);
    $domain = activeDomain($this->w->partnerA, 'g1.partner-a.test', $this->w->g1);
    $grant = app(SupportAccessService::class)->request($this->w->partnerA, $this->w->c1, createPartnerStaff($this->w->partnerA), 'Checking the fee report', Severity::Normal, 60);
    toggles()->enable($this->w->c1, 'custom_reports', 'Reports');
    partnerRule($this->w->partnerB, 'partners.allowed_modules', ['hrm', 'attendance', 'payroll']);

    $code = transferCode($this);
    $preview = $this->asToken($this->ownerToken)->postJson("http://localhost/api/organizations/{$this->w->g1->id}/transfer/preview", ['code' => $code])->assertOk();
    expect($preview->json('data.partner_plan_ends'))->toBe('School Plus')
        ->and($preview->json('data.domains_end'))->toBe(['g1.partner-a.test'])
        ->and($preview->json('data.support_access_ends'))->toBe(1)
        ->and($preview->json('data.open_invoices'))->toBe(1);

    Event::fake([ModuleDisabled::class]);
    $transfer = askToMove($this, ['code' => $code])->json('data.id');
    $this->asToken($this->partnerBToken)->postJson("http://localhost/api/partner/transfers/{$transfer}/accept")->assertOk();

    expect(Subscription::where('organization_id', $this->w->g1->id)->value('partner_plan_id'))->toBeNull()
        ->and($domain->fresh()->status)->toBe(DomainStatus::Disabled)
        ->and($grant->fresh()->status->value)->toBe('revoked')
        // The old partner's invoice stays with it.
        ->and(Invoice::where('organization_id', $this->w->g1->id)->sole()->partner_id)->toBe($this->w->partnerA->id)
        // The new partner does not offer this module: off, data kept.
        ->and(resolvedModule('custom_reports', $this->w->c1)->enabled)->toBeFalse();
    Event::assertDispatched(ModuleDisabled::class, fn ($event) => $event->moduleKey === 'custom_reports');
});

it('lets the platform move every client of a closed partner to the house partner', function () {
    Partner::factory()->house()->create(['name' => 'One Solutions']);

    $this->artisan('clients:transfer', ['to' => 'house', '--all-from' => $this->w->partnerA->slug, '--reason' => 'Partner A closed'])
        ->expectsOutputToContain('2 moved to One Solutions')
        ->assertSuccessful();

    expect(Organization::where('partner_id', $this->w->partnerA->id)->count())->toBe(0)
        ->and(ClientTransfer::where('by_platform', true)->where('status', 'completed')->count())->toBe(2);
});

it('shows the client its provider, and the move only to the account owner', function () {
    askToMove($this, ['code' => transferCode($this)]);
    $companyOwner = createMember($this->w->c1);

    $this->asToken($this->ownerToken)->getJson("http://localhost/api/organizations/{$this->w->g1->id}/provider")->assertOk()
        ->assertJsonPath('data.provider.name', 'Partner A')
        ->assertJsonPath('data.transfer.to.name', 'Partner B')
        ->assertJsonPath('data.can_transfer', true);

    $this->asToken(orgToken($companyOwner, $this->w->c1))->getJson("http://localhost/api/organizations/{$this->w->c1->id}/provider")->assertOk()
        ->assertJsonPath('data.transfer', ['status' => 'awaiting_partner'])
        ->assertJsonPath('data.can_transfer', false);
});

it('shows transfer codes once and lets partners revoke them', function () {
    $response = $this->asToken($this->partnerBToken)->postJson('http://localhost/api/partner/transfer-codes', ['label' => 'Sunrise'])->assertCreated();
    $code = $response->json('data.code');

    $list = $this->asToken($this->partnerBToken)->getJson('http://localhost/api/partner/transfers')->assertOk()
        ->assertJsonPath('codes.0.label', 'Sunrise')
        ->assertJsonPath('codes.0.hint', substr($code, -4))
        ->assertJsonPath('codes.0.status', 'active');
    expect($list->getContent())->not->toContain($code);

    $this->asToken($this->partnerBToken)->deleteJson('http://localhost/api/partner/transfer-codes/'.$list->json('codes.0.id'))->assertOk();
    askToMove($this, ['code' => $code])->assertUnprocessable()->assertJsonPath('code', 'invalid_code');

    $support = partnerToken(createPartnerStaff($this->w->partnerB, PartnerUserRole::Support), $this->w->partnerB);
    $this->asToken($support)->postJson('http://localhost/api/partner/transfer-codes', [])->assertForbidden();
});
