<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Tenancy\Context\CurrentContext;
use Carbon\CarbonImmutable;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Ledger\LedgerEntry;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Services\Ledger;

/*
 * Cash, wallets and banks below their floor: cash in hand and wallets may
 * not go below zero, a bank below minus its overdraft limit, counted on the
 * entry's own date. By rule: block, warn (confirm with a reason) or allow.
 * Reversals always go through. With the rule on, nothing is posted before
 * the opening balances, nor dated before their day.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 06:00:00', 'UTC'));
    $this->w = accountingWorld();
    setUpBooks($this, $this->w->token, $this->w->c1)->assertCreated();
    $this->api = fn (string $path) => "/api/organizations/{$this->w->c1->id}/accounting/{$path}";
    $this->entry = fn (array $lines, array $data = [], ?string $token = null) => journalVia($this, $token ?? $this->w->token, $this->w->c1, $lines, ['submit' => true, ...$data]);
    $this->step = fn (string $journal, string $step, array $data, ?string $token = null) => $this->asToken($token ?? $this->w->token)
        ->postJson(($this->api)("journals/{$journal}/{$step}"), $data);
    $this->withHeader('Origin', config('app.url'));
});

it('marks cash, bank and wallet accounts when the books are set up', function () {
    $accounts = collect($this->asToken($this->w->token)->getJson(($this->api)('accounts'))->assertOk()->json('data'))->keyBy('code');
    expect($accounts['1110'])->toMatchArray(['money_kind' => 'cash', 'overdraft_limit_minor' => 0])
        ->and($accounts['1120']['money_kind'])->toBe('bank')
        ->and($accounts['1130']['money_kind'])->toBe('wallet')
        ->and($accounts['5300']['money_kind'])->toBeNull();

    // Only a postable asset holds money; only a bank has an overdraft.
    $rent = $accounts['5300'];
    $this->asToken($this->w->token)->patchJson(($this->api)("accounts/{$rent['id']}"), ['base_version' => $rent['version'], 'money_kind' => 'cash'])
        ->assertUnprocessable()->assertJsonValidationErrors('money_kind');
    $cash = $accounts['1110'];
    $this->asToken($this->w->token)->patchJson(($this->api)("accounts/{$cash['id']}"), ['base_version' => $cash['version'], 'overdraft_limit_minor' => 1000])
        ->assertUnprocessable()->assertJsonValidationErrors('overdraft_limit_minor');
    $this->asToken($this->w->viewerToken)->patchJson(($this->api)("accounts/{$cash['id']}"), ['base_version' => $cash['version'], 'money_kind' => null])->assertForbidden();
});

it('refuses taking cash below zero on the entry\'s date, and nothing is saved', function () {
    ($this->entry)([['5300', 50000, 0], ['1110', 0, 50000]])->assertUnprocessable()
        ->assertJsonPath('code', 'below_floor')
        ->assertJsonPath('accounts.0.balance_minor', -50000)
        ->assertJsonPath('accounts.0.kind', 'cash');
    expect(Journal::query()->count())->toBe(0);

    // Owner puts 1 000 in on 15 October; 600 of rent then goes through, 500 more does not.
    ($this->entry)([['1110', 100000, 0], ['3100', 0, 100000]])->assertCreated();
    ($this->entry)([['5300', 60000, 0], ['1110', 0, 60000]])->assertCreated()->assertJsonPath('data.status', 'posted');
    ($this->entry)([['5300', 50000, 0], ['1110', 0, 50000]])->assertUnprocessable()->assertJsonPath('accounts.0.balance_minor', -10000);
    // The balance counts on the entry's own date: before the money came in, there was none.
    ($this->entry)([['5300', 10000, 0], ['1110', 0, 10000]], ['entry_date' => '2026-10-14'])->assertUnprocessable()->assertJsonPath('code', 'below_floor');
});

it('asks before taking a bank below zero, and posts with a reason that is kept', function () {
    $warning = ($this->entry)([['5300', 30000, 0], ['1120', 0, 30000]])->assertStatus(409)
        ->assertJsonPath('code', 'below_floor_warning')
        ->assertJsonPath('accounts.0.kind', 'bank')
        ->json();
    expect($warning['message'])->toContain('1120');
    expect(Journal::query()->count())->toBe(0);

    ($this->entry)([['5300', 30000, 0], ['1120', 0, 30000]], ['allow_negative' => true])->assertUnprocessable()->assertJsonValidationErrors('negative_reason');
    ($this->entry)([['5300', 30000, 0], ['1120', 0, 30000]], ['allow_negative' => true, 'negative_reason' => 'Paid by cheque, deposit comes tomorrow'])
        ->assertCreated()->assertJsonPath('data.status', 'posted');
    $logged = AuditLog::query()->where('action', 'accounting.negative_balance_posted')->sole();
    expect($logged->reason)->toBe('Paid by cheque, deposit comes tomorrow');
});

it('lets a bank use its overdraft without asking, and asks beyond it', function () {
    $bank = collect($this->asToken($this->w->token)->getJson(($this->api)('accounts'))->json('data'))->firstWhere('code', '1120');
    $this->asToken($this->w->token)->patchJson(($this->api)("accounts/{$bank['id']}"), ['base_version' => $bank['version'], 'overdraft_limit_minor' => 50000])
        ->assertOk()->assertJsonPath('data.overdraft_limit_minor', 50000);

    ($this->entry)([['5300', 30000, 0], ['1120', 0, 30000]])->assertCreated()->assertJsonPath('data.status', 'posted');
    ($this->entry)([['5300', 30000, 0], ['1120', 0, 30000]])->assertStatus(409)->assertJsonPath('accounts.0.balance_minor', -60000);
});

it('asks the approver too, when an entry waiting for approval would take cash below zero', function () {
    trustedOrgRule($this->w->c1, 'accounting.negative_cash_balance', 'warn');
    trustedOrgRule($this->w->c1, 'accounting.journal_approval_above', ['amount' => 0, 'currency' => 'BDT']);
    // Waiting for approval: nothing is checked until it is posted.
    $pending = ($this->entry)([['5300', 20000, 0], ['1110', 0, 20000]])->assertCreated()->assertJsonPath('data.status', 'pending_approval')->json('data');

    ($this->step)($pending['id'], 'approve', ['base_version' => $pending['version']], $this->w->approverToken)->assertStatus(409)->assertJsonPath('code', 'below_floor_warning');
    ($this->step)($pending['id'], 'approve', ['base_version' => $pending['version'], 'allow_negative' => true, 'negative_reason' => 'Petty cash book not entered yet'], $this->w->approverToken)
        ->assertOk()->assertJsonPath('data.status', 'posted');
});

it('always lets a mistake be reversed, even when cash goes below zero', function () {
    $in = ($this->entry)([['1110', 100000, 0], ['3100', 0, 100000]])->assertCreated()->json('data');
    ($this->entry)([['5300', 100000, 0], ['1110', 0, 100000]])->assertCreated();

    ($this->step)($in['id'], 'reverse', ['base_version' => $in['version'], 'reason' => 'Capital came in December, not October'])->assertCreated()->assertJsonPath('data.status', 'posted');
});

it('follows the rule set above the company, and allows everything when told to', function () {
    trustedOrgRule($this->w->g1, 'accounting.negative_cash_balance', 'allow');
    ($this->entry)([['5300', 50000, 0], ['1110', 0, 50000]])->assertCreated()->assertJsonPath('data.status', 'posted');

    // The company may be stricter than its group.
    trustedOrgRule($this->w->c1, 'accounting.negative_cash_balance', 'block');
    ($this->entry)([['5300', 50000, 0], ['1110', 0, 50000]])->assertUnprocessable()->assertJsonPath('code', 'below_floor');
});

it('posts nothing before the opening balances, nor dated before their day, when the rule says so', function () {
    trustedOrgRule($this->w->c1, 'accounting.require_opening_first', true);
    ($this->entry)([['1110', 100000, 0], ['3100', 0, 100000]])->assertUnprocessable()->assertJsonPath('code', 'opening_first');

    $draft = $this->asToken($this->w->token)->putJson(($this->api)('opening'), ['opening_date' => '2026-10-18', 'lines' => [
        ['kind' => 'account', 'account_id' => accountId($this->w->c1, '1110'), 'debit_minor' => 500000],
        ['kind' => 'account', 'account_id' => accountId($this->w->c1, '3100'), 'credit_minor' => 500000],
    ]])->assertOk()->json('data');
    $this->asToken($this->w->token)->postJson(($this->api)('opening/submit'), ['base_version' => $draft['version']])->assertOk()->assertJsonPath('data.status', 'posted');

    ($this->entry)([['5300', 20000, 0], ['1110', 0, 20000]], ['entry_date' => '2026-10-16'])->assertUnprocessable()->assertJsonPath('code', 'before_opening');
    ($this->entry)([['5300', 20000, 0], ['1110', 0, 20000]], ['entry_date' => '2026-10-19'])->assertCreated()->assertJsonPath('data.status', 'posted');
});

it('keeps another company\'s money out of the count', function () {
    setUpBooks($this, orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['accounting.view', 'accounting.post', 'accounting.manage'], 'C2 books')), $this->w->c2), $this->w->c2)->assertCreated();
    $c2 = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['accounting.view', 'accounting.post'], 'C2 accountant')), $this->w->c2);
    journalVia($this, $c2, $this->w->c2, [['1110', 900000, 0], ['3100', 0, 900000]], ['submit' => true])->assertCreated();

    // C2's cash does not cover C1's rent.
    ($this->entry)([['5300', 50000, 0], ['1110', 0, 50000]])->assertUnprocessable()->assertJsonPath('code', 'below_floor');
});

it('lets another module post through a warning and notes it, but never through a block', function () {
    app(CurrentContext::class)->clear();
    $pay = fn (string $opId) => new LedgerEntry(
        opId: $opId, entryDate: '2026-10-15', narration: 'Salaries paid', sourceModule: 'payroll', sourceType: 'run', sourceId: 'RUN-1', currency: 'BDT',
        lines: [LedgerLine::debit('payroll.salaries_payable', 40000), LedgerLine::credit('payroll.payment_account', 40000)],
    );

    // The bank (warn) goes below zero: posted, and noted with no reason given.
    expect(app(Ledger::class)->post($this->w->c1, $pay('pay-1'))->isPosted())->toBeTrue();
    expect(AuditLog::query()->where('action', 'accounting.negative_balance_posted')->sole()->reason)->toBeNull();

    // Blocked: the module's posting is refused like anyone else's.
    trustedOrgRule($this->w->c1, 'accounting.negative_bank_balance', 'block');
    app()->forgetScopedInstances();
    app(CurrentContext::class)->clear();
    expect(fn () => app(Ledger::class)->post($this->w->c1, $pay('pay-2')))->toThrow(AccountingException::class, 'below_floor');
});
