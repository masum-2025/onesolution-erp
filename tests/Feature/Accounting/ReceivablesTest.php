<?php

use App\Platform\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\Settlement;

/*
 * ACC-3a: customers and vendors, invoices and bills, credits, money
 * received and paid, and who owes what (aging, statements).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 06:00:00', 'UTC'));
    $this->w = accountingWorld();
    setUpBooks($this, $this->w->token, $this->w->c1)->assertCreated();
    // Sells and buys; never approves (separation of duties).
    $this->clerk = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['accounting.view', 'accounting.sell', 'accounting.buy'], 'Clerk')), $this->w->c1);
    $this->api = fn (string $path = '') => "/api/organizations/{$this->w->c1->id}/accounting/{$path}";
});

function partyVia(object $test, array $data = []): TestResponse
{
    return $test->asToken($test->clerk)->postJson(($test->api)('parties'), ['name' => 'Karim Traders', 'is_customer' => true, ...$data]);
}

/**
 * @param  list<array{0: string, 1: string, 2: int}>  $lines  [account code, quantity, unit price minor]
 */
function documentVia(object $test, string $type, string $partyId, array $lines, array $data = []): TestResponse
{
    return $test->asToken($test->clerk)->postJson(($test->api)('documents'), [
        'type' => $type,
        'party_id' => $partyId,
        'issue_date' => '2026-10-15',
        'lines' => array_map(fn (array $line) => ['description' => 'Item', 'quantity' => $line[1], 'unit_price_minor' => $line[2], 'account_id' => accountId($test->w->c1, $line[0])], $lines),
        ...$data,
    ]);
}

function settlementVia(object $test, string $type, string $partyId, int $amount, array $allocations, array $data = []): TestResponse
{
    return $test->asToken($test->clerk)->postJson(($test->api)('settlements'), [
        'type' => $type,
        'party_id' => $partyId,
        'settled_on' => '2026-10-15',
        'account_id' => accountId($test->w->c1, '1120'),
        'amount_minor' => $amount,
        'allocations' => $allocations,
        ...$data,
    ]);
}

function trialRow(object $test, string $code, string $asOf = '2026-10-15'): ?array
{
    return collect($test->asToken($test->w->viewerToken)->getJson(($test->api)("reports/trial-balance?as_of={$asOf}"))->json('data.rows'))->firstWhere('code', $code);
}

it('keeps customers and vendors, each looked after by the people who sell or buy', function () {
    $customer = partyVia($this, ['code' => 'C-001', 'phone' => '+8801711000000', 'payment_terms_days' => 15])->assertCreated()->json('data');
    expect($customer)->toMatchArray(['name' => 'Karim Traders', 'is_customer' => true, 'is_vendor' => false, 'payment_terms_days' => 15, 'version' => 1]);

    partyVia($this, ['code' => 'C-001'])->assertUnprocessable()->assertJsonValidationErrors('code');
    partyVia($this, ['is_customer' => false])->assertUnprocessable()->assertJsonValidationErrors('is_customer');
    partyVia($this, ['organization_id' => $this->w->c2->id])->assertUnprocessable()->assertJsonValidationErrors('organization_id');

    // A writer of journals is not a seller.
    $this->asToken($this->w->token)->postJson(($this->api)('parties'), ['name' => 'Someone', 'is_customer' => true])->assertForbidden();
    $seller = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['accounting.view', 'accounting.sell'], 'Seller')), $this->w->c1);
    $this->asToken($seller)->postJson(($this->api)('parties'), ['name' => 'Rahman Paper Mills', 'is_vendor' => true])->assertForbidden();

    $this->asToken($this->clerk)->patchJson(($this->api)('parties/'.$customer['id']), ['base_version' => 1, 'is_vendor' => true])->assertOk()->assertJsonPath('data.is_vendor', true);
    expect(collect($this->asToken($this->w->viewerToken)->getJson(($this->api)('parties?role=vendors'))->json('data'))->pluck('name')->all())->toBe(['Karim Traders']);
});

it('posts an invoice: quantity times price, due by the terms, receivable against income', function () {
    $party = partyVia($this, ['payment_terms_days' => 15])->json('data.id');

    $draft = documentVia($this, 'invoice', $party, [['4100', '1.5', 100000], ['4200', '3', 33333]])->assertCreated()->json('data');
    expect($draft)->toMatchArray(['status' => 'draft', 'number' => null, 'total_minor' => 249999, 'due_date' => '2026-10-30', 'balance_minor' => 249999])
        ->and($draft['lines'][0])->toMatchArray(['quantity' => '1.5', 'amount_minor' => 150000, 'account_code' => '4100'])
        ->and($draft['lines'][1]['amount_minor'])->toBe(99999);

    $posted = $this->asToken($this->clerk)->postJson(($this->api)("documents/{$draft['id']}/submit"), ['base_version' => 1])->assertOk()->json('data');
    expect($posted)->toMatchArray(['status' => 'posted', 'number' => 'INV-2026-00001'])
        ->and($posted['journal_id'])->not->toBeNull();

    expect(trialRow($this, '1140')['debit_minor'])->toBe(249999)
        ->and(trialRow($this, '4100')['credit_minor'])->toBe(150000)
        ->and(trialRow($this, '4200')['credit_minor'])->toBe(99999);

    $journal = Journal::query()->findOrFail($posted['journal_id']);
    expect($journal->only(['source_module', 'source_type', 'source_id', 'number']))->toBe(['source_module' => 'accounting', 'source_type' => 'document', 'source_id' => $draft['id'], 'number' => 'JV-2026-00001']);

    // Posted documents never change; their journal is undone by voiding the document, not on the journal page.
    $this->asToken($this->clerk)->patchJson(($this->api)("documents/{$draft['id']}"), ['base_version' => $posted['version'], 'notes' => 'x'])->assertStatus(409);
    $this->asToken($this->w->token)->postJson(($this->api)("journals/{$journal->id}/reverse"), ['base_version' => $journal->version, 'reason' => 'Trying it here'])
        ->assertStatus(409)->assertJsonPath('code', 'sourced_journal');
    expect($this->asToken($this->w->token)->getJson(($this->api)("journals/{$journal->id}"))->json('data.can.reverse'))->toBeFalse();
});

it('refuses lines on the wrong kind of account, and parties of the wrong kind', function () {
    $customer = partyVia($this)->json('data.id');
    $vendor = partyVia($this, ['name' => 'Rahman Paper Mills', 'is_customer' => false, 'is_vendor' => true])->json('data.id');

    documentVia($this, 'invoice', $customer, [['5300', '1', 100]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');
    documentVia($this, 'invoice', $customer, [['4100', '0', 100]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');
    documentVia($this, 'invoice', $customer, [['4100', '1.2345', 100]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');
    documentVia($this, 'invoice', $vendor, [['4100', '1', 100]])->assertUnprocessable()->assertJsonValidationErrors('party_id');
    // A bill may buy equipment (an asset) or an expense, never income.
    documentVia($this, 'bill', $vendor, [['1220', '1', 100], ['5500', '1', 100]], ['submit' => true])->assertCreated();
    documentVia($this, 'bill', $vendor, [['4100', '1', 100]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');
});

it('lets a second person approve documents above the approval amount', function () {
    trustedOrgRule($this->w->c1, 'accounting.journal_approval_above', ['amount' => 100000, 'currency' => 'BDT']);
    $party = partyVia($this)->json('data.id');

    $sent = documentVia($this, 'invoice', $party, [['4100', '2', 100000]], ['submit' => true])->assertCreated()->json('data');
    expect($sent)->toMatchArray(['status' => 'pending_approval', 'number' => null, 'journal_id' => null]);
    $url = ($this->api)("documents/{$sent['id']}");

    $this->asToken($this->clerk)->postJson("{$url}/approve", ['base_version' => $sent['version']])->assertForbidden();
    $rejected = $this->asToken($this->w->approverToken)->postJson("{$url}/reject", ['base_version' => $sent['version'], 'reason' => 'Wrong price'])->assertOk()->json('data');
    expect($rejected)->toMatchArray(['status' => 'rejected', 'reject_reason' => 'Wrong price']);

    $changed = $this->asToken($this->clerk)->patchJson($url, ['base_version' => $rejected['version'], 'lines' => [['description' => 'Desk', 'quantity' => '2', 'unit_price_minor' => 90000, 'account_id' => accountId($this->w->c1, '4100')]]])->assertOk()->json('data');
    $again = $this->asToken($this->clerk)->postJson("{$url}/submit", ['base_version' => $changed['version']])->assertOk()->json('data');
    $approved = $this->asToken($this->w->approverToken)->postJson("{$url}/approve", ['base_version' => $again['version']])->assertOk()->json('data');

    expect($approved)->toMatchArray(['status' => 'posted', 'number' => 'INV-2026-00001', 'total_minor' => 180000]);
    expect(Journal::query()->findOrFail($approved['journal_id'])->approved_by)->toBe($this->w->approver->id)
        ->and(Document::query()->findOrFail($sent['id'])->approved_by)->toBe($this->w->approver->id);
});

it('records money received once per op id, and marks the invoice paid', function () {
    $party = partyVia($this)->json('data.id');
    $invoice = documentVia($this, 'invoice', $party, [['4100', '1', 500000]], ['submit' => true])->json('data');

    $part = settlementVia($this, 'receipt', $party, 200000, [['document_id' => $invoice['id'], 'amount_minor' => 200000]], ['op_id' => 'app-1'])->assertCreated()->json('data');
    expect($part)->toMatchArray(['status' => 'posted', 'number' => 'RCPT-2026-00001', 'allocated_minor' => 200000])
        ->and($part['allocations'][0])->toMatchArray(['document_number' => 'INV-2026-00001', 'amount_minor' => 200000]);
    settlementVia($this, 'receipt', $party, 200000, [['document_id' => $invoice['id'], 'amount_minor' => 200000]], ['op_id' => 'app-1'])->assertOk()->assertJsonPath('data.id', $part['id']);

    expect($this->asToken($this->w->viewerToken)->getJson(($this->api)("documents/{$invoice['id']}"))->json('data'))
        ->toMatchArray(['status' => 'partly_paid', 'balance_minor' => 300000]);

    // More than is due, a document of someone else, or money left over (advances are off): refused.
    settlementVia($this, 'receipt', $party, 400000, [['document_id' => $invoice['id'], 'amount_minor' => 400000]])->assertUnprocessable()->assertJsonValidationErrors('allocations.0.amount_minor');
    $other = partyVia($this, ['name' => 'Other customer'])->json('data.id');
    settlementVia($this, 'receipt', $other, 100, [['document_id' => $invoice['id'], 'amount_minor' => 100]])->assertUnprocessable()->assertJsonValidationErrors('allocations.0.document_id');
    settlementVia($this, 'receipt', $party, 350000, [['document_id' => $invoice['id'], 'amount_minor' => 300000]])->assertUnprocessable()->assertJsonValidationErrors('amount_minor');

    settlementVia($this, 'receipt', $party, 300000, [['document_id' => $invoice['id'], 'amount_minor' => 300000]])->assertCreated();
    expect(Document::query()->findOrFail($invoice['id'])->status->value)->toBe('paid')
        ->and(trialRow($this, '1120')['debit_minor'])->toBe(500000)
        ->and(trialRow($this, '1140'))->toBeNull();
});

it('keeps an advance when the rule allows it, and sets it against a later invoice', function () {
    trustedOrgRule($this->w->c1, 'accounting.allow_overpayment', true);
    $party = partyVia($this)->json('data.id');

    $advance = settlementVia($this, 'receipt', $party, 100000, [])->assertCreated()->json('data');
    expect($advance)->toMatchArray(['allocated_minor' => 0, 'unallocated_minor' => 100000])
        ->and($advance['can']['allocate'])->toBeTrue();

    $invoice = documentVia($this, 'invoice', $party, [['4100', '1', 60000]], ['submit' => true])->json('data');
    $after = $this->asToken($this->clerk)->postJson(($this->api)("settlements/{$advance['id']}/allocate"), [
        'base_version' => $advance['version'], 'allocations' => [['document_id' => $invoice['id'], 'amount_minor' => 60000]],
    ])->assertOk()->json('data');

    expect($after['unallocated_minor'])->toBe(40000)
        ->and(Document::query()->findOrFail($invoice['id'])->status->value)->toBe('paid');
});

it('voids money and documents: payments first, journals reversed, books back to zero', function () {
    $party = partyVia($this)->json('data.id');
    $invoice = documentVia($this, 'invoice', $party, [['4100', '1', 70000]], ['submit' => true])->json('data');
    $receipt = settlementVia($this, 'receipt', $party, 70000, [['document_id' => $invoice['id'], 'amount_minor' => 70000]])->json('data');

    $paid = $this->asToken($this->w->approverToken)->getJson(($this->api)("documents/{$invoice['id']}"))->json('data');
    $this->asToken($this->w->approverToken)->postJson(($this->api)("documents/{$invoice['id']}/void"), ['base_version' => $paid['version'], 'reason' => 'Wrong customer'])
        ->assertStatus(409)->assertJsonPath('code', 'document_has_payments');
    // Only approvers void.
    $this->asToken($this->clerk)->postJson(($this->api)("settlements/{$receipt['id']}/void"), ['base_version' => $receipt['version'], 'reason' => 'Bounced cheque'])->assertForbidden();

    $voided = $this->asToken($this->w->approverToken)->postJson(($this->api)("settlements/{$receipt['id']}/void"), ['base_version' => $receipt['version'], 'reason' => 'Bounced cheque'])->assertOk()->json('data');
    expect($voided)->toMatchArray(['status' => 'void', 'allocated_minor' => 0]);
    $open = $this->asToken($this->w->approverToken)->getJson(($this->api)("documents/{$invoice['id']}"))->assertJsonPath('data.status', 'posted')->json('data');

    $this->asToken($this->w->approverToken)->postJson(($this->api)("documents/{$invoice['id']}/void"), ['base_version' => $open['version'], 'reason' => 'Wrong customer'])
        ->assertOk()->assertJsonPath('data.status', 'void');

    expect(trialRow($this, '1140'))->toBeNull()->and(trialRow($this, '4100'))->toBeNull()->and(trialRow($this, '1120'))->toBeNull()
        ->and(Journal::query()->where('source_type', 'document')->where('source_id', $invoice['id'])->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'accounting.document_voided')->sole()->reason)->toBe('Wrong customer');
    expect(fn () => Settlement::query()->findOrFail($receipt['id'])->delete())->toThrow(LogicException::class);
});

it('applies a credit note to an invoice of the same customer', function () {
    $party = partyVia($this)->json('data.id');
    $invoice = documentVia($this, 'invoice', $party, [['4100', '1', 100000]], ['submit' => true])->json('data');
    $credit = documentVia($this, 'credit_note', $party, [['4100', '1', 30000]], ['submit' => true])->json('data');
    expect($credit['number'])->toBe('CN-2026-00001')->and(trialRow($this, '1140')['debit_minor'])->toBe(70000);

    $this->asToken($this->clerk)->postJson(($this->api)("documents/{$invoice['id']}/apply"), ['base_version' => $invoice['version'], 'allocations' => [['document_id' => $credit['id'], 'amount_minor' => 1]]])
        ->assertUnprocessable()->assertJsonPath('code', 'not_a_credit');
    $used = $this->asToken($this->clerk)->postJson(($this->api)("documents/{$credit['id']}/apply"), [
        'base_version' => $credit['version'], 'allocations' => [['document_id' => $invoice['id'], 'amount_minor' => 30000]],
    ])->assertOk()->json('data');

    expect($used)->toMatchArray(['status' => 'paid', 'balance_minor' => 0])
        ->and($this->asToken($this->clerk)->getJson(($this->api)("documents/{$invoice['id']}"))->json('data'))->toMatchArray(['status' => 'partly_paid', 'balance_minor' => 70000]);
});

it('does the same for bills, vendor credits and money paid, on the payables account', function () {
    $vendor = partyVia($this, ['name' => 'Rahman Paper Mills', 'is_customer' => false, 'is_vendor' => true])->json('data.id');
    $bill = documentVia($this, 'bill', $vendor, [['5500', '10', 2500]], ['submit' => true])->assertCreated()->json('data');
    expect($bill['number'])->toBe('BILL-2026-00001')
        ->and(trialRow($this, '2110')['credit_minor'])->toBe(25000)
        ->and(trialRow($this, '5500')['debit_minor'])->toBe(25000);

    $paid = settlementVia($this, 'payment', $vendor, 25000, [['document_id' => $bill['id'], 'amount_minor' => 25000]])->assertCreated()->json('data');
    expect($paid['number'])->toBe('PAY-2026-00001')
        ->and(trialRow($this, '2110'))->toBeNull()
        ->and(trialRow($this, '1120')['credit_minor'])->toBe(25000);
});

it('ages what customers owe on any day, less credits and advances', function () {
    trustedOrgRule($this->w->c1, 'accounting.allow_backdated_entries_days', 366);
    trustedOrgRule($this->w->c1, 'accounting.allow_overpayment', true);
    $karim = partyVia($this, ['payment_terms_days' => 0])->json('data.id');
    $nila = partyVia($this, ['name' => 'Nila Store', 'payment_terms_days' => 0])->json('data.id');

    documentVia($this, 'invoice', $karim, [['4100', '1', 10000]], ['submit' => true, 'issue_date' => '2026-10-10']); // 5 days late
    documentVia($this, 'invoice', $karim, [['4100', '1', 20000]], ['submit' => true, 'issue_date' => '2026-08-31']); // 45 days late
    $old = documentVia($this, 'invoice', $nila, [['4100', '1', 40000]], ['submit' => true, 'issue_date' => '2026-07-01'])->json('data'); // 106 days late
    documentVia($this, 'invoice', $nila, [['4100', '1', 5000]], ['submit' => true, 'issue_date' => '2026-10-15', 'due_date' => '2026-11-14']); // not due
    settlementVia($this, 'receipt', $karim, 3000, []); // advance

    $aging = $this->asToken($this->w->viewerToken)->getJson(($this->api)('reports/aging?side=sales&as_of=2026-10-15'))->assertOk()->json('data');
    $rows = collect($aging['rows'])->keyBy('party_name');
    expect($aging['buckets'])->toBe(['current', 'upto_30', 'upto_60', 'upto_90', 'over_90'])
        ->and($rows['Karim Traders']['buckets'])->toBe(['current' => 0, 'upto_30' => 10000, 'upto_60' => 20000, 'upto_90' => 0, 'over_90' => 0])
        ->and($rows['Karim Traders'])->toMatchArray(['credits_minor' => 3000, 'net_minor' => 27000])
        ->and($rows['Nila Store']['buckets'])->toMatchArray(['current' => 5000, 'over_90' => 40000])
        ->and($aging['totals']['net_minor'])->toBe(72000);

    // Paid today: still owed as of yesterday.
    settlementVia($this, 'receipt', $nila, 40000, [['document_id' => $old['id'], 'amount_minor' => 40000]]);
    $now = collect($this->asToken($this->w->viewerToken)->getJson(($this->api)('reports/aging?side=sales&as_of=2026-10-15'))->json('data.rows'))->keyBy('party_name');
    $before = collect($this->asToken($this->w->viewerToken)->getJson(($this->api)('reports/aging?side=sales&as_of=2026-10-14'))->json('data.rows'))->keyBy('party_name');
    expect($now['Nila Store']['buckets']['over_90'])->toBe(0)
        ->and($before['Nila Store']['buckets']['over_90'])->toBe(40000);
});

it('gives a customer statement with an opening balance and a running balance', function () {
    trustedOrgRule($this->w->c1, 'accounting.allow_backdated_entries_days', 366);
    $party = partyVia($this)->json('data.id');
    $first = documentVia($this, 'invoice', $party, [['4100', '1', 50000]], ['submit' => true, 'issue_date' => '2026-09-01'])->json('data');
    documentVia($this, 'invoice', $party, [['4100', '1', 30000]], ['submit' => true, 'issue_date' => '2026-10-02']);
    settlementVia($this, 'receipt', $party, 50000, [['document_id' => $first['id'], 'amount_minor' => 50000]], ['settled_on' => '2026-10-05']);

    $statement = $this->asToken($this->w->viewerToken)->getJson(($this->api)("reports/statement?side=sales&party_id={$party}&from=2026-10-01&to=2026-10-31"))->assertOk()->json('data');
    expect($statement['opening_minor'])->toBe(50000)
        ->and(array_column($statement['rows'], 'kind'))->toBe(['invoice', 'receipt'])
        ->and(array_column($statement['rows'], 'balance_minor'))->toBe([80000, 30000])
        ->and($statement['closing_minor'])->toBe(30000);

    expect($this->asToken($this->w->viewerToken)->getJson(($this->api)("parties/{$party}"))->json('data.balances'))->toBe(['sales' => 30000, 'purchases' => 0]);
});

it('keeps customers, documents and money of one company away from the others', function () {
    $party = partyVia($this)->json('data.id');
    $invoice = documentVia($this, 'invoice', $party, [['4100', '1', 1000]], ['submit' => true])->json('data');

    $c2Owner = createMember($this->w->c2);
    $c2Token = orgToken($c2Owner, $this->w->c2);
    setUpBooks($this, $c2Token, $this->w->c2)->assertCreated();
    $c2Clerk = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['accounting.view', 'accounting.sell'], 'Clerk')), $this->w->c2);
    $c2 = fn (string $path) => "/api/organizations/{$this->w->c2->id}/accounting/{$path}";

    $this->asToken($c2Clerk)->getJson($c2("documents/{$invoice['id']}"))->assertNotFound();
    $this->asToken($c2Clerk)->getJson($c2("parties/{$party}"))->assertNotFound();
    $this->asToken($c2Clerk)->getJson(($this->api)("documents/{$invoice['id']}"))->assertNotFound();
    $this->asToken($c2Clerk)->postJson($c2('documents'), [
        'type' => 'invoice', 'party_id' => $party, 'issue_date' => '2026-10-15',
        'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price_minor' => 1, 'account_id' => accountId($this->w->c2, '4100')]],
    ])->assertUnprocessable()->assertJsonValidationErrors('party_id');
    expect($this->asToken($c2Clerk)->getJson($c2('documents'))->json('meta.total'))->toBe(0);

    $c4Token = orgToken(createMember($this->w->c4), $this->w->c4);
    $this->asToken($c4Token)->getJson(($this->api)('parties'))->assertNotFound();

    // Viewers read but do not write; the module off closes it all.
    partyVia($this)->assertCreated();
    $this->asToken($this->w->viewerToken)->postJson(($this->api)('parties'), ['name' => 'Xenon Ltd', 'is_customer' => true])->assertForbidden();
    toggles()->disable($this->w->g1, 'accounting', 'Test setup', confirm: true);
    $this->asToken($this->clerk)->getJson(($this->api)('documents'))->assertForbidden();
});

it('waits for approval on large money, applies the allocations only then, and lists money and documents', function () {
    trustedOrgRule($this->w->c1, 'accounting.journal_approval_above', ['amount' => 100000, 'currency' => 'BDT']);
    trustedOrgRule($this->w->c1, 'accounting.allow_backdated_entries_days', 366);
    $party = partyVia($this, ['payment_terms_days' => 10])->json('data.id');
    $invoice = documentVia($this, 'invoice', $party, [['4100', '1', 90000]], ['submit' => true, 'issue_date' => '2026-09-01'])->json('data');
    documentVia($this, 'invoice', $party, [['4100', '1', 50000]], ['submit' => true]);

    $big = settlementVia($this, 'receipt', $party, 150000, [['document_id' => $invoice['id'], 'amount_minor' => 90000]])->assertUnprocessable()->assertJsonValidationErrors('amount_minor');
    trustedOrgRule($this->w->c1, 'accounting.allow_overpayment', true);
    $big = settlementVia($this, 'receipt', $party, 150000, [['document_id' => $invoice['id'], 'amount_minor' => 90000]])->assertCreated()->json('data');
    expect($big)->toMatchArray(['status' => 'pending_approval', 'number' => null, 'allocated_minor' => 0])
        ->and($big['requested_allocations'])->toHaveCount(1)
        ->and(Document::query()->findOrFail($invoice['id'])->status->value)->toBe('posted');

    $this->asToken($this->clerk)->postJson(($this->api)("settlements/{$big['id']}/approve"), ['base_version' => 1])->assertForbidden();
    $posted = $this->asToken($this->w->approverToken)->postJson(($this->api)("settlements/{$big['id']}/approve"), ['base_version' => 1])->assertOk()->json('data');
    expect($posted)->toMatchArray(['status' => 'posted', 'number' => 'RCPT-2026-00001', 'allocated_minor' => 90000, 'unallocated_minor' => 60000, 'requested_allocations' => null])
        ->and(Document::query()->findOrFail($invoice['id'])->status->value)->toBe('paid');

    $small = settlementVia($this, 'receipt', $party, 1000, [], ['settled_on' => '2026-10-01'])->json('data');
    $rejected = settlementVia($this, 'receipt', $party, 200000, [])->json('data');
    $this->asToken($this->w->approverToken)->postJson(($this->api)("settlements/{$rejected['id']}/reject"), ['base_version' => 1, 'reason' => 'No such cheque'])->assertOk()->assertJsonPath('data.status', 'rejected');

    $list = fn (string $query) => $this->asToken($this->w->viewerToken)->getJson(($this->api)("settlements{$query}"))->assertOk()->json();
    expect($list('?type=receipt')['meta']['total'])->toBe(3)
        ->and(array_column($list('?unallocated=1')['data'], 'id'))->toEqualCanonicalizing([$big['id'], $small['id']])
        ->and(array_column($list('?status=rejected')['data'], 'id'))->toBe([$rejected['id']])
        ->and($list('?from=2026-10-02')['meta']['total'])->toBe(2)
        ->and($this->asToken($this->w->viewerToken)->getJson(($this->api)("settlements/{$big['id']}"))->json('data.allocations.0.document_number'))->toBe('INV-2026-00001');

    $documents = fn (string $query) => $this->asToken($this->w->viewerToken)->getJson(($this->api)("documents{$query}"))->assertOk()->json();
    expect($documents('?side=sales')['meta']['total'])->toBe(2)
        ->and($documents('?status=open')['meta']['total'])->toBe(1)
        ->and($documents('?overdue=1')['meta']['total'])->toBe(0)
        ->and($documents('?q=Karim')['data'][0]['party_name'])->toBe('Karim Traders')
        ->and($documents('?side=purchases')['meta']['total'])->toBe(0);
});
