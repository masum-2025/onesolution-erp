<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Modules\Accounting\Models\Settlement;
use Modules\Compliance\Models\PaymentAdvice as ClientAdvice;
use Modules\Projects\Models\PaymentAdvice;

/*
 * Over a business connection: the client (C1, factory B1) tells its
 * provider Apex (C3) that it paid an invoice Apex sent. Nothing is posted
 * by the advice itself; Apex checks the money arrived and records the
 * receipt in its books, or answers that nothing came. The client sees the
 * answer.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 06:00:00', 'UTC'));
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        setPlan($group, 'business');
        foreach (['compliance', 'business_network', 'crm', 'projects'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    toggles()->enable($this->w->g2, 'accounting', 'Test setup');
    $staff = function (Organization $at, array $permissions, string $name) {
        $user = createMember($at, MembershipType::Staff, AccessScope::Descendants);
        giveRoles($user, $at, makeRole($at, $permissions, $name));

        return $user;
    };
    $as = fn (User $user, Organization $at) => fn () => $this->asToken(orgToken($user, $at));
    $apexOwner = createMember($this->w->c3);
    $groupOwner = $as(createMember($this->w->g1), $this->w->g1);
    $provider = $as($apexOwner, $this->w->c3);
    $hracUser = $staff($this->w->c1, ['compliance.view', 'compliance.manage', 'compliance.set_expiry', 'compliance.request'], 'HRAC');
    $this->hrac = $as($hracUser, $this->w->c1);
    $this->buyerUser = $staff($this->w->c1, ['compliance.view', 'compliance.bills'], 'Payables');
    $this->buyer = $as($this->buyerUser, $this->w->c1);
    $this->billerUser = $staff($this->w->c3, ['projects.view', 'projects.bill', 'accounting.view', 'accounting.sell'], 'Biller');
    $this->biller = $as($this->billerUser, $this->w->c3);
    $this->answerer = $as($staff($this->w->c3, ['projects.view', 'projects.bill'], 'Project biller'), $this->w->c3);
    $this->viewer = $as($staff($this->w->c3, ['projects.view'], 'Apex viewer'), $this->w->c3);
    $this->client = fn (string $path, ?Organization $at = null) => '/api/organizations/'.($at ?? $this->w->c1)->id."/compliance/{$path}";
    $this->apex = fn (string $path = '') => "/api/organizations/{$this->w->c3->id}/projects".($path === '' ? '' : "/{$path}");

    // Connected, a renewal request accepted as a project, its invoice posted and sent.
    $code = $provider()->postJson("/api/organizations/{$this->w->c3->id}/connections/invitations", [])->assertCreated()->json('data.code');
    $connection = $groupOwner()->postJson("/api/organizations/{$this->w->g1->id}/connections/accept", ['code' => $code, 'unit_ids' => [$this->w->c1->id]])->assertCreated()->json('data.id');
    $groupOwner()->postJson(($this->client)('lists/services/defaults', $this->w->g1))->assertOk();
    $apexInList = $groupOwner()->postJson(($this->client)('lists/providers', $this->w->g1), ['name' => 'Apex', 'connection_id' => $connection])->assertCreated()->json('data.id');
    $services = collect($groupOwner()->getJson(($this->client)('lists/services', $this->w->g1))->json('data'))->pluck('id', 'code');
    $line = ($this->hrac)()->postJson(($this->client)('audits'), ['unit_id' => $this->w->b1->id, 'service_id' => $services['BSCI'], 'provider_id' => $apexInList, 'expires_on' => '2026-12-01'])->assertCreated()->json('data');
    ($this->hrac)()->postJson(($this->client)("audits/{$line['id']}/renewal"), ['wanted_by' => '2026-11-25'])->assertCreated();
    $incoming = $provider()->getJson(($this->apex)('requests'))->json('data.0');
    $this->projectId = $provider()->postJson(($this->apex)("requests/{$incoming['id']}/accept"), ['starts_on' => '2026-11-10'])->assertCreated()->json('data.project_id');
    setUpBooks($this, orgToken($apexOwner, $this->w->c3), $this->w->c3)->assertCreated();
    $invoice = ($this->biller)()->postJson(($this->apex)("{$this->projectId}/invoices"), ['issue_date' => '2026-10-28', 'lines' => [['description' => 'BSCI audit', 'quantity_milli' => 1000, 'unit_price_minor' => 100000]]])->assertCreated()->json('data');
    ($this->biller)()->postJson("/api/organizations/{$this->w->c3->id}/accounting/documents/{$invoice['id']}/submit", ['base_version' => 1])->assertOk();
    ($this->biller)()->postJson(($this->apex)("{$this->projectId}/invoices/{$invoice['id']}/send"))->assertOk();
    $this->invoiceId = $invoice['id'];
    $this->copy = ($this->buyer)()->getJson(($this->client)('renewals'))->assertOk()->json('data.invoices.0');
    $this->advise = fn (array $data) => ($this->buyer)()->postJson(($this->client)("invoices/{$this->copy['id']}/advices"), [
        'amount_minor' => 60000, 'paid_on' => '2026-10-28', 'method' => 'bank', 'reference' => 'TRX-1', 'op_id' => 'adv-1', ...$data,
    ]);
    $this->money = fn () => ($this->biller)()->getJson(($this->apex)("{$this->projectId}/money"))->assertOk()->json('data');
});

it('tells the provider about a payment; the provider records it as a receipt and the client sees it', function () {
    expect($this->copy)->toMatchArray(['open_minor' => 100000, 'advices' => []])->and($this->copy['can']['advise'])->toBeTrue();

    $told = ($this->advise)([])->assertCreated()->json('data');
    expect($told['open_minor'])->toBe(40000)
        ->and($told['advices'][0])->toMatchArray(['amount_minor' => 60000, 'currency_code' => 'BDT', 'method' => 'bank', 'reference' => 'TRX-1', 'status' => 'sent']);
    // The same tap twice: one advice, one message.
    ($this->advise)([])->assertCreated()->assertJsonCount(1, 'data.advices');
    expect(ClientAdvice::query()->withoutGlobalScope(OrganizationScope::class)->count())->toBe(1)
        ->and(PaymentAdvice::query()->withoutGlobalScope(OrganizationScope::class)->count())->toBe(1);

    // Apex sees it; nothing is posted yet; the person who sent the invoice is told.
    $money = ($this->money)();
    expect($money['advices'][0])->toMatchArray(['from' => 'B1', 'amount_minor' => 60000, 'paid_on' => '2026-10-28', 'reference' => 'TRX-1', 'status' => 'new', 'settlement_id' => null])
        ->and($money['can']['record_payment'])->toBeTrue()
        ->and($money['money_accounts'])->not->toBeEmpty()
        ->and(Settlement::query()->withoutGlobalScope(OrganizationScope::class)->count())->toBe(0)
        ->and(NotificationDelivery::query()->where('notification_key', 'projects.payment_advised')->where('user_id', $this->billerUser->id)->count())->toBe(1);
    $advice = $money['advices'][0];
    $account = collect($money['money_accounts'])->firstWhere('code', '1120') ?? $money['money_accounts'][0];

    // A viewer cannot record; someone without accounting.sell cannot either.
    ($this->viewer)()->postJson(($this->apex)("{$this->projectId}/advices/{$advice['id']}/record"), ['base_version' => 1, 'account_id' => $account['id'], 'settled_on' => '2026-10-28'])->assertForbidden();
    ($this->answerer)()->postJson(($this->apex)("{$this->projectId}/advices/{$advice['id']}/record"), ['base_version' => 1, 'account_id' => $account['id'], 'settled_on' => '2026-10-28'])->assertForbidden();
    ($this->biller)()->postJson(($this->apex)("{$this->projectId}/advices/{$advice['id']}/record"), ['base_version' => 1, 'settled_on' => '2026-10-28'])->assertUnprocessable()->assertJsonValidationErrors('account_id');

    $recorded = ($this->biller)()->postJson(($this->apex)("{$this->projectId}/advices/{$advice['id']}/record"), ['base_version' => 1, 'account_id' => $account['id'], 'settled_on' => '2026-10-28'])
        ->assertOk()->json('data');
    expect($recorded['status'])->toBe('recorded')->and($recorded['settlement_id'])->not->toBeNull();
    $receipt = Settlement::query()->withoutGlobalScope(OrganizationScope::class)->sole();
    expect($receipt->only(['amount_minor', 'allocated_minor', 'reference', 'op_id']))->toMatchArray(['amount_minor' => 60000, 'allocated_minor' => 60000, 'reference' => 'TRX-1', 'op_id' => "advice-{$advice['id']}"])
        ->and($receipt->settled_on->toDateString())->toBe('2026-10-28')
        ->and(collect(($this->money)()['invoices'])->firstWhere('id', $this->invoiceId)['balance_minor'])->toBe(40000);
    // Decided once.
    ($this->biller)()->postJson(($this->apex)("{$this->projectId}/advices/{$advice['id']}/record"), ['base_version' => 2, 'account_id' => $account['id'], 'settled_on' => '2026-10-29'])
        ->assertStatus(409)->assertJsonPath('code', 'advice_decided');

    // The client sees the answer, and the person who told is told.
    $copy = ($this->buyer)()->getJson(($this->client)('renewals'))->json('data.invoices.0');
    expect($copy['advices'][0]['status'])->toBe('recorded')
        ->and(NotificationDelivery::query()->where('notification_key', 'compliance.advice_answered')->where('user_id', $this->buyerUser->id)->count())->toBe(1);
    // Sending the invoice again brings the client's copy up to date.
    ($this->biller)()->postJson(($this->apex)("{$this->projectId}/invoices/{$this->invoiceId}/send"))->assertOk();
    expect(($this->buyer)()->getJson(($this->client)('renewals'))->json('data.invoices.0'))->toMatchArray(['status' => 'partly_paid', 'balance_minor' => 40000, 'open_minor' => 40000]);
    expect(AuditLog::query()->whereIn('action', ['compliance.payment_advised', 'projects.payment_advice_received', 'projects.payment_recorded', 'compliance.payment_recorded'])->count())->toBe(4);
});

it('answers that the money did not arrive, with a reason the client sees', function () {
    ($this->advise)([])->assertCreated();
    $advice = ($this->money)()['advices'][0];

    ($this->answerer)()->postJson(($this->apex)("{$this->projectId}/advices/{$advice['id']}/not-received"), ['base_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('reason');
    ($this->answerer)()->postJson(($this->apex)("{$this->projectId}/advices/{$advice['id']}/not-received"), ['base_version' => 1, 'reason' => 'Nothing in the bank on 28 October'])
        ->assertOk()->assertJsonPath('data.status', 'not_received');
    expect(Settlement::query()->withoutGlobalScope(OrganizationScope::class)->count())->toBe(0);

    $copy = ($this->buyer)()->getJson(($this->client)('renewals'))->json('data.invoices.0');
    expect($copy['advices'][0])->toMatchArray(['status' => 'not_received', 'reply_reason' => 'Nothing in the bank on 28 October'])
        // No longer waiting: the whole amount can be told again.
        ->and($copy['open_minor'])->toBe(100000);
});

it('checks the amount, the day and who tells', function () {
    ($this->advise)(['amount_minor' => 100001])->assertUnprocessable()->assertJsonPath('code', 'advice_more_than_owed');
    ($this->advise)(['paid_on' => '2026-10-30'])->assertUnprocessable()->assertJsonPath('code', 'advice_in_future');
    ($this->advise)(['method' => 'gold'])->assertUnprocessable()->assertJsonValidationErrors('method');
    ($this->advise)(['currency_code' => 'USD'])->assertUnprocessable();
    // Waiting advices count: 60 000 told, only 40 000 more.
    ($this->advise)([])->assertCreated();
    ($this->advise)(['op_id' => 'adv-2', 'amount_minor' => 40001])->assertUnprocessable()->assertJsonPath('code', 'advice_more_than_owed');
    ($this->advise)(['op_id' => 'adv-2', 'amount_minor' => 40000])->assertCreated()->assertJsonPath('data.open_minor', 0)->assertJsonPath('data.can.advise', false);
    ($this->advise)(['op_id' => 'adv-3', 'amount_minor' => 1])->assertUnprocessable()->assertJsonPath('code', 'invoice_nothing_owed');

    // Without compliance.bills: no.
    ($this->hrac)()->postJson(($this->client)("invoices/{$this->copy['id']}/advices"), ['amount_minor' => 1, 'paid_on' => '2026-10-28', 'method' => 'cash', 'op_id' => 'x-1'])->assertForbidden();
});

it('keeps advices inside the connection, and stops with the module off', function () {
    ($this->advise)([])->assertCreated();
    $advice = ($this->money)()['advices'][0];

    // Another partner's company, and the provider on the client's address: not found.
    $other = fn () => $this->asToken(orgToken(createMember($this->w->c4), $this->w->c4));
    $other()->postJson(($this->client)("invoices/{$this->copy['id']}/advices", $this->w->c4), ['amount_minor' => 1, 'paid_on' => '2026-10-28', 'method' => 'cash', 'op_id' => 'x-1'])->assertNotFound();
    ($this->biller)()->postJson(($this->client)("invoices/{$this->copy['id']}/advices"), ['amount_minor' => 1, 'paid_on' => '2026-10-28', 'method' => 'cash', 'op_id' => 'x-1'])->assertNotFound();
    $other()->postJson("/api/organizations/{$this->w->c4->id}/projects/{$this->projectId}/advices/{$advice['id']}/not-received", ['base_version' => 1, 'reason' => 'Not mine'])->assertNotFound();
    expect(json_encode($other()->getJson(($this->client)('renewals', $this->w->c4))->json()))->not->toContain('TRX-1');

    // Compliance off at the client: no advice; Projects off at the provider: no answer.
    toggles()->disable($this->w->g1, 'compliance', 'Test');
    ($this->advise)(['op_id' => 'adv-2', 'amount_minor' => 1])->assertForbidden();
    toggles()->disable($this->w->g2, 'projects', 'Test');
    ($this->answerer)()->postJson(($this->apex)("{$this->projectId}/advices/{$advice['id']}/not-received"), ['base_version' => 1, 'reason' => 'Nothing came'])->assertForbidden();
});
