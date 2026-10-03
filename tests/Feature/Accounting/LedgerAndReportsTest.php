<?php

use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Modules\Accounting\Events\JournalPosted;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Ledger\LedgerEntry;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\PostingAccount;
use Modules\Accounting\Services\Ledger;

/*
 * ACC-1: other modules post through the Ledger service (posting keys, never
 * account ids), and reports add up posted entries only.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 06:00:00', 'UTC'));
    $this->w = accountingWorld();
    setUpBooks($this, $this->w->token, $this->w->c1)->assertCreated();
});

/** An entry another module would send: opening balances moved between two equity accounts. */
function ledgerEntry(string $opId, int $amount = 25000, string $date = '2026-10-15', string $currency = 'BDT'): LedgerEntry
{
    return new LedgerEntry(
        opId: $opId, entryDate: $date, narration: 'Opening balances', sourceModule: 'payroll', sourceType: 'run', sourceId: 'RUN-1', currency: $currency,
        lines: [LedgerLine::debit('accounting.retained_earnings', $amount), LedgerLine::credit('accounting.opening_balance', $amount)],
    );
}

it('posts another module\'s entry through its posting keys, once per op id', function () {
    Event::fake([JournalPosted::class]);
    app(CurrentContext::class)->clear();

    $first = app(Ledger::class)->post($this->w->c1, ledgerEntry('op-1'));
    $again = app(Ledger::class)->post($this->w->c1, ledgerEntry('op-1'));

    expect($first->isPosted())->toBeTrue()
        ->and($first->number)->toBe('JV-2026-00001')
        ->and($again->id)->toBe($first->id)
        ->and(Journal::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->count())->toBe(1);

    $journal = Journal::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->findOrFail($first->id);
    expect($journal->only(['source_module', 'source_type', 'source_id', 'created_by']))->toBe(['source_module' => 'payroll', 'source_type' => 'run', 'source_id' => 'RUN-1', 'created_by' => null]);
    Event::assertDispatched(JournalPosted::class, fn (JournalPosted $event) => $event->sourceModule === 'payroll' && $event->sourceId === 'RUN-1');
});

it('needs an open period, but not the people\'s date window, and waits for approval above the amount', function () {
    app(CurrentContext::class)->clear();

    // A month back is fine for a system posting (people may only go 7 days back).
    expect(app(Ledger::class)->post($this->w->c1, ledgerEntry('op-old', date: '2026-08-31'))->isPosted())->toBeTrue();
    expect(fn () => app(Ledger::class)->post($this->w->c1, ledgerEntry('op-before', date: '2026-06-30')))->toThrow(AccountingException::class, 'no_period');

    trustedOrgRule($this->w->c1, 'accounting.journal_approval_above', ['amount' => 10000, 'currency' => 'BDT']);
    app(CurrentContext::class)->clear();
    expect(app(Ledger::class)->post($this->w->c1, ledgerEntry('op-big'))->status)->toBe('pending_approval');
});

it('refuses entries it cannot place: unmapped key, other currency, books not set up, module off', function () {
    app(CurrentContext::class)->clear();
    expect(fn () => app(Ledger::class)->post($this->w->c1, ledgerEntry('op-usd', currency: 'USD')))->toThrow(AccountingException::class, 'currency_not_supported')
        ->and(fn () => app(Ledger::class)->post($this->w->c2, ledgerEntry('op-c2')))->toThrow(AccountingException::class, 'not_set_up')
        ->and(fn () => app(Ledger::class)->post($this->w->b1, ledgerEntry('op-b1')))->toThrow(AccountingException::class, 'not_company');

    PostingAccount::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->where('posting_key', 'accounting.opening_balance')->delete();
    expect(fn () => app(Ledger::class)->post($this->w->c1, ledgerEntry('op-unmapped')))->toThrow(AccountingException::class, 'posting_account_missing');

    toggles()->disable($this->w->g1, 'accounting', 'Test setup', confirm: true);
    app(CurrentContext::class)->clear();
    expect(fn () => app(Ledger::class)->post($this->w->c1, ledgerEntry('op-off')))->toThrow(AccountingException::class, 'module_off')
        ->and(Journal::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->count())->toBe(0);
});

/** The books used by the report tests (all posted). */
function postReportEntries(object $test): void
{
    trustedOrgRule($test->w->c1, 'accounting.allow_backdated_entries_days', 366);
    $post = fn (array $lines, string $date) => journalVia($test, $test->w->token, $test->w->c1, $lines, ['submit' => true, 'entry_date' => $date])->assertCreated();

    $post([['5500', 5000, 0], ['1110', 0, 5000]], '2026-07-05');
    $post([['1110', 100000, 0], ['4100', 0, 100000, $test->w->d1->id]], '2026-09-20');
    $post([['1110', 500000, 0], ['3100', 0, 500000]], '2026-10-15');
    $post([['5300', 20000, 0, $test->w->b1->id], ['1110', 0, 20000]], '2026-10-15');
    // Not in the books: a draft.
    journalVia($test, $test->w->token, $test->w->c1, [['5300', 999, 0], ['1110', 0, 999]])->assertCreated();
}

it('balances the trial balance on any day, from whole periods and parts of one', function () {
    postReportEntries($this);
    $report = fn (string $asOf) => collect($this->asToken($this->w->viewerToken)->getJson("/api/organizations/{$this->w->c1->id}/accounting/reports/trial-balance?as_of={$asOf}")->assertOk()->json('data'));

    $today = $report('2026-10-15');
    $rows = collect($today['rows'])->keyBy('code');
    expect($rows['1110'])->toMatchArray(['debit_minor' => 575000, 'credit_minor' => 0, 'name' => 'Cash in hand'])
        ->and($rows['3100']['credit_minor'])->toBe(500000)
        ->and($rows['5300']['debit_minor'])->toBe(20000)
        ->and([$today['total_debit_minor'], $today['total_credit_minor']])->toBe([600000, 600000])
        ->and($today['currency'])->toBe('BDT');

    // End of September: three whole periods from the period totals.
    $september = collect($report('2026-09-30')['rows'])->keyBy('code');
    expect($september['1110']['debit_minor'])->toBe(95000)->and($september->has('3100'))->toBeFalse();
    // Part of September from the lines themselves: the same, and before the sale the cash is short.
    expect(collect($report('2026-09-25')['rows'])->keyBy('code')['1110']['debit_minor'])->toBe(95000)
        ->and(collect($report('2026-09-19')['rows'])->keyBy('code')['1110'])->toMatchArray(['debit_minor' => 0, 'credit_minor' => 5000]);
});

it('shows profit and loss and a balance sheet that balances, also per branch', function () {
    postReportEntries($this);
    $get = fn (string $path) => $this->asToken($this->w->viewerToken)->getJson("/api/organizations/{$this->w->c1->id}/accounting/reports/{$path}")->assertOk()->json('data');

    $profit = $get('profit-loss?from=2026-07-01&to=2026-10-31');
    expect([$profit['income']['total_minor'], $profit['expense']['total_minor'], $profit['net_profit_minor']])->toBe([100000, 25000, 75000]);

    // Branch B1 with its department D1: the rent and the sale; D1 alone: the sale.
    expect($get("profit-loss?from=2026-07-01&to=2026-10-31&cost_centre_id={$this->w->b1->id}")['net_profit_minor'])->toBe(80000)
        ->and($get("profit-loss?from=2026-07-01&to=2026-10-31&cost_centre_id={$this->w->d1->id}")['net_profit_minor'])->toBe(100000);

    $sheet = $get('balance-sheet?as_of=2026-10-15');
    expect($sheet['assets']['total_minor'])->toBe(575000)
        ->and($sheet['equity']['total_minor'])->toBe(500000)
        ->and($sheet['earnings_to_date_minor'])->toBe(75000)
        ->and($sheet['total_liabilities_and_equity_minor'])->toBe($sheet['assets']['total_minor']);

    $this->asToken($this->w->viewerToken)->getJson("/api/organizations/{$this->w->c1->id}/accounting/reports/profit-loss?from=2026-07-01&to=2026-10-31&cost_centre_id={$this->w->b2->id}")
        ->assertUnprocessable()->assertJsonValidationErrors('cost_centre_id');
});

it('lists an account\'s entries with the balance before, after each line and at the end', function () {
    postReportEntries($this);
    $cash = accountId($this->w->c1, '1110');

    $ledger = $this->asToken($this->w->viewerToken)->getJson("/api/organizations/{$this->w->c1->id}/accounting/reports/ledger?account_id={$cash}&from=2026-09-01&to=2026-10-31")->assertOk()->json('data');
    expect($ledger['opening_minor'])->toBe(-5000)
        ->and($ledger['rows'])->toHaveCount(3)
        ->and($ledger['rows'][0])->toMatchArray(['entry_date' => '2026-09-20', 'debit_minor' => 100000, 'balance_minor' => 95000, 'number' => 'JV-2026-00002'])
        ->and($ledger['closing_minor'])->toBe(575000);

    $this->asToken($this->w->viewerToken)->getJson("/api/organizations/{$this->w->c1->id}/accounting/reports/ledger?from=2026-09-01&to=2026-10-31")
        ->assertUnprocessable()->assertJsonValidationErrors('account_id');
    $this->asToken($this->w->viewerToken)->getJson("/api/organizations/{$this->w->c1->id}/accounting/reports/cash-flow")->assertNotFound();
});
