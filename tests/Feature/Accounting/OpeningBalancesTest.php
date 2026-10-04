<?php

use App\Platform\Audit\AuditLog;
use Carbon\CarbonImmutable;

/*
 * ACC-4b: the balances a company brings from its old books. Accounts with a
 * debit or credit, customers and vendors as old invoices and bills (so aging
 * works), the difference to the opening balance account; posted once, with
 * approval above the company's amount, never by the person who wrote it.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 06:00:00', 'UTC'));
    $this->w = accountingWorld();
    setUpBooks($this, $this->w->token, $this->w->c1)->assertCreated();
    $this->api = fn (string $path) => "/api/organizations/{$this->w->c1->id}/accounting/{$path}";
    $clerk = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['accounting.view', 'accounting.sell', 'accounting.buy'], 'Clerk')), $this->w->c1);
    $party = fn (array $data) => $this->asToken($clerk)->postJson(($this->api)('parties'), $data)->assertCreated()->json('data.id');
    $this->karim = $party(['name' => 'Karim Traders', 'is_customer' => true]);
    $this->paper = $party(['name' => 'Dhaka Paper House', 'is_vendor' => true, 'payment_terms_days' => 30]);

    $this->lines = fn () => [
        ['kind' => 'account', 'account_id' => accountId($this->w->c1, '1110'), 'debit_minor' => 500000],
        ['kind' => 'account', 'account_id' => accountId($this->w->c1, '3100'), 'credit_minor' => 400000],
        ['kind' => 'customer', 'party_id' => $this->karim, 'amount_minor' => 150000, 'reference' => 'OLD-17', 'issue_date' => '2026-05-10', 'due_date' => '2026-06-09'],
        ['kind' => 'vendor', 'party_id' => $this->paper, 'amount_minor' => 80000, 'reference' => 'P-88', 'issue_date' => '2026-06-20'],
    ];
    $this->save = fn (array $lines, array $data = [], ?string $token = null) => $this->asToken($token ?? $this->w->token)
        ->putJson(($this->api)('opening'), ['opening_date' => '2026-07-01', 'lines' => $lines, ...$data]);
    $this->step = fn (string $step, int $version, array $data = [], ?string $token = null) => $this->asToken($token ?? $this->w->token)
        ->postJson(($this->api)("opening/{$step}"), ['base_version' => $version, ...$data]);
    $this->row = fn (string $code) => collect($this->asToken($this->w->viewerToken)->getJson(($this->api)('reports/trial-balance?as_of=2026-10-15'))->json('data.rows'))->firstWhere('code', $code);
});

it('brings balances in, customers and vendors as old invoices and bills, the rest to opening balance', function () {
    $this->asToken($this->w->viewerToken)->getJson(($this->api)('opening'))->assertOk()
        ->assertJsonPath('data', null)->assertJsonPath('meta.suggested_date', '2026-07-01')->assertJsonPath('meta.can_write', false);

    $draft = ($this->save)(($this->lines)())->assertOk()->json('data');
    expect($draft)->toMatchArray(['status' => 'draft', 'version' => 1, 'debit_minor' => 650000, 'credit_minor' => 480000, 'difference_minor' => 170000])
        ->and($draft['lines'][3])->toMatchArray(['party_name' => 'Dhaka Paper House', 'credit_minor' => 80000, 'due_date' => '2026-07-20'])
        ->and($draft['can'])->toMatchArray(['edit' => true, 'submit' => true, 'approve' => false]);

    $posted = ($this->step)('submit', 1)->assertOk()->json('data');
    expect($posted)->toMatchArray(['status' => 'posted'])->and($posted['journal_id'])->not->toBeNull();

    expect(($this->row)('1110')['debit_minor'])->toBe(500000)
        ->and(($this->row)('1140')['debit_minor'])->toBe(150000)
        ->and(($this->row)('2110')['credit_minor'])->toBe(80000)
        ->and(($this->row)('3100')['credit_minor'])->toBe(400000)
        ->and(($this->row)('3300')['credit_minor'])->toBe(170000);

    // The customer's old invoice is open (and late) like any other; it cannot be voided, only credited.
    $invoices = collect($this->asToken($this->w->viewerToken)->getJson(($this->api)('documents?type=invoice'))->assertOk()->json('data'));
    $old = $invoices->sole();
    expect($old)->toMatchArray(['number' => 'OB-00001', 'reference' => 'OLD-17', 'issue_date' => '2026-05-10', 'is_opening' => true, 'balance_minor' => 150000]);
    $aging = collect($this->asToken($this->w->viewerToken)->getJson(($this->api)('reports/aging?side=sales&as_of=2026-10-15'))->json('data.rows'))->firstWhere('party_name', 'Karim Traders');
    expect($aging['buckets']['over_90'])->toBe(150000);
    $this->asToken($this->w->approverToken)->postJson(($this->api)("documents/{$old['id']}/void"), ['base_version' => $old['version'], 'reason' => 'Wrong amount'])
        ->assertConflict()->assertJsonPath('code', 'opening_document');

    ($this->save)(($this->lines)(), ['base_version' => $posted['version']])->assertConflict()->assertJsonPath('code', 'opening_posted');
    expect(AuditLog::query()->where('action', 'accounting.opening_posted')->count())->toBe(1);
});

it('waits for a second person above the approval amount', function () {
    trustedOrgRule($this->w->c1, 'accounting.journal_approval_above', ['amount' => 100000, 'currency' => 'BDT']);
    ($this->save)(($this->lines)())->assertOk();

    $pending = ($this->step)('submit', 1)->assertOk()->json('data');
    expect($pending['status'])->toBe('pending_approval')->and(($this->row)('1110'))->toBeNull();
    ($this->save)(($this->lines)(), ['base_version' => 2])->assertConflict()->assertJsonPath('code', 'not_editable');
    ($this->step)('approve', 2)->assertForbidden();

    // Rejected: changed and sent again, then approved by someone else.
    ($this->step)('reject', 2, ['reason' => 'Cash looks too high'], $this->w->approverToken)->assertOk()->assertJsonPath('data.status', 'rejected');
    $lines = ($this->lines)();
    $lines[0]['debit_minor'] = 400000;
    $fixed = ($this->save)($lines, ['base_version' => 3])->assertOk()->json('data');
    expect($fixed)->toMatchArray(['status' => 'draft', 'difference_minor' => 70000]);
    ($this->step)('submit', $fixed['version'])->assertOk();
    $approved = ($this->step)('approve', $fixed['version'] + 1, [], $this->w->approverToken)->assertOk()->json('data');

    expect($approved['status'])->toBe('posted')
        ->and(($this->row)('1110')['debit_minor'])->toBe(400000)
        ->and(($this->row)('3300')['credit_minor'])->toBe(70000);
});

it('checks every line and the date, and keeps the worked-out accounts out', function () {
    ($this->save)([['kind' => 'account', 'account_id' => accountId($this->w->c1, '1140'), 'debit_minor' => 1000]])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');
    ($this->save)([['kind' => 'account', 'account_id' => accountId($this->w->c1, '1110'), 'debit_minor' => 1000, 'credit_minor' => 1000]])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.debit_minor');
    ($this->save)([['kind' => 'customer', 'party_id' => $this->paper, 'amount_minor' => 1000, 'issue_date' => '2026-06-01']])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.party_id');
    ($this->save)([['kind' => 'customer', 'party_id' => $this->karim, 'amount_minor' => 1000, 'issue_date' => '2026-08-01']])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.issue_date');
    ($this->save)([['kind' => 'customer', 'party_id' => $this->karim, 'amount_minor' => 1000, 'issue_date' => '2026-06-01', 'due_date' => '2026-05-01']])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.due_date');
    ($this->save)([['kind' => 'account', 'account_id' => accountId($this->w->c1, '1110'), 'debit_minor' => 1000, 'organization_id' => $this->w->c2->id]])
        ->assertUnprocessable();
    ($this->save)(($this->lines)(), ['opening_date' => '2025-01-01'])->assertUnprocessable()->assertJsonValidationErrors('opening_date');

    // Nothing saved; a draft can be thrown away; the writer cannot approve it.
    $draft = ($this->save)(($this->lines)())->assertOk()->json('data');
    $this->asToken($this->w->token)->deleteJson(($this->api)('opening'), ['base_version' => 1])->assertNoContent();
    $this->asToken($this->w->viewerToken)->getJson(($this->api)('opening'))->assertJsonPath('data', null);
    expect($draft['can']['approve'])->toBeFalse();
});

it('keeps opening balances to the company and to people with the right', function () {
    ($this->save)(($this->lines)(), [], $this->w->viewerToken)->assertForbidden();
    ($this->save)(($this->lines)(), [], $this->w->approverToken)->assertForbidden();
    ($this->save)(($this->lines)())->assertOk();

    $c2Token = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['accounting.view', 'accounting.manage'], 'Books')), $this->w->c2);
    $this->asToken($c2Token)->getJson(($this->api)('opening'))->assertNotFound();
    $this->asToken($c2Token)->postJson(($this->api)('opening/submit'), ['base_version' => 1])->assertNotFound();

    toggles()->disable($this->w->g1, 'accounting', 'Test setup', confirm: true);
    $this->asToken($this->w->token)->getJson(($this->api)('opening'))->assertForbidden();
});
