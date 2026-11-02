<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Support\MoneyText;
use App\Platform\Tenancy\Models\Organization;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Models\Opening;

/**
 * Money accounts below their floor, checked as a journal is posted. Cash in
 * hand and wallets may not go below zero, a bank account below minus its
 * overdraft limit; the balance counted is the one on the entry's own date.
 * Each kind follows its rule: allow, warn (someone in Accounting's own
 * screens confirms with a reason; other modules' postings go through and
 * are noted) or block. Reversals always go through: undoing a mistake must
 * stay possible. With accounting.require_opening_first, nothing is posted
 * before the opening balances are, nor dated before their day.
 *
 * Confirming a warning is one per request: the controller calls
 * acknowledge() before the posting call.
 */
class MoneyFloors
{
    private ?string $acknowledged = null;

    public function __construct(
        private Books $books,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /** The person saw the warning and posts anyway, for this reason. */
    public function acknowledge(?string $reason): void
    {
        $this->acknowledged = $reason === null ? null : mb_substr(trim($reason), 0, 500);
    }

    public function check(Organization $company, Journal $journal, ?User $actor): void
    {
        if ($journal->reverses_id !== null || in_array($journal->source_type, [Openings::SOURCE_TYPE, YearEnd::SOURCE_TYPE], true)) {
            return;
        }
        $context = $this->contexts->forOrganization($company);
        if ((bool) $this->rules->get('accounting.require_opening_first', $context)) {
            $this->assertAfterOpening($company, $journal);
        }

        $lines = $this->books->linesOf($company, $journal)->get();
        $credited = $lines->groupBy('account_id')
            ->map(fn ($group) => (int) $group->sum('credit_minor') - (int) $group->sum('debit_minor'))
            ->filter(fn (int $net) => $net > 0);
        if ($credited->isEmpty()) {
            return;
        }
        $accounts = $this->books->query(Account::class, $company)->whereKey($credited->keys()->all())->whereNotNull('money_kind')->get();

        $below = [];
        foreach ($accounts as $account) {
            $mode = (string) $this->rules->get($account->money_kind === 'bank' ? 'accounting.negative_bank_balance' : 'accounting.negative_cash_balance', $context);
            if ($mode === 'allow') {
                continue;
            }
            $after = $this->balanceOn($company, $account, $journal);
            $floor = $account->money_kind === 'bank' ? -$account->overdraft_limit_minor : 0;
            if ($after >= $floor) {
                continue;
            }
            $below[] = ['account' => $account, 'after' => $after, 'mode' => $mode];
        }
        if ($below === []) {
            return;
        }

        $locale = app()->getLocale();
        $details = array_map(fn (array $item) => [
            'account_id' => $item['account']->getKey(),
            'account' => "{$item['account']->code} {$item['account']->name}",
            'kind' => $item['account']->money_kind,
            'balance_minor' => $item['after'],
            'balance' => MoneyText::format($item['after'], $journal->currency_code, $locale),
            'mode' => $item['mode'],
        ], $below);
        $date = $journal->entry_date->toDateString();

        if (collect($below)->contains(fn (array $item) => $item['mode'] === 'block')) {
            throw AccountingException::belowFloor($details, $date);
        }
        // A warning: people posting in Accounting's own screens confirm it; other modules' postings are noted.
        $ownScreens = $actor !== null && ($journal->source_module === null || $journal->source_module === 'accounting');
        if ($ownScreens && $this->acknowledged === null) {
            throw AccountingException::belowFloorWarning($details, $date);
        }
        $this->audit->record('accounting.negative_balance_posted', $journal, new: ['accounts' => array_map(fn (array $item) => [$item['account'], $item['balance_minor']], $details), 'entry_date' => $date],
            reason: $this->acknowledged, actor: $actor, organizationId: $company->getKey());
    }

    /** The account's balance (debit less credit) on the entry's date, this journal included. */
    private function balanceOn(Organization $company, Account $account, Journal $journal): int
    {
        $posted = fn () => $this->books->query(JournalLine::class, $company)
            ->join('acc_journals', 'acc_journals.id', '=', 'acc_journal_lines.journal_id')
            ->where('acc_journal_lines.account_id', $account->getKey())
            ->where('acc_journals.status', JournalStatus::Posted->value)
            ->where('acc_journals.entry_date', '<=', $journal->entry_date->toDateString())
            ->where('acc_journals.id', '!=', $journal->getKey());
        $debit = (int) $posted()->sum('acc_journal_lines.debit_minor');
        $credit = (int) $posted()->sum('acc_journal_lines.credit_minor');
        $own = $this->books->linesOf($company, $journal)->where('account_id', $account->getKey())->get();

        return $debit - $credit + (int) $own->sum('debit_minor') - (int) $own->sum('credit_minor');
    }

    private function assertAfterOpening(Organization $company, Journal $journal): void
    {
        $opening = $this->books->query(Opening::class, $company)->first();
        if ($opening === null || $opening->status !== JournalStatus::Posted) {
            throw AccountingException::openingFirst();
        }
        if ($journal->entry_date->toDateString() < $opening->opening_date->toDateString()) {
            throw AccountingException::beforeOpening($opening->opening_date->toDateString());
        }
    }
}
