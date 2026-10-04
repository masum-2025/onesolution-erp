<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Http\AccountingPresenter;
use Modules\Accounting\Http\Controllers\Concerns\FindsBooks;
use Modules\Accounting\Http\Requests\BankImportRequest;
use Modules\Accounting\Http\Requests\BankLineRequest;
use Modules\Accounting\Http\Requests\ReconciliationRequest;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\BankLine;
use Modules\Accounting\Models\BankMatch;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Models\Reconciliation;
use Modules\Accounting\Services\BankMatching;
use Modules\Accounting\Services\BankStatements;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\Reconciliations;

/**
 * Matching cash, bank and wallet accounts with their statements: read
 * (accounting.view); import, match, finish and reopen (accounting.reconcile);
 * writing a missing entry also needs accounting.post.
 */
class BankController extends Controller
{
    use FindsBooks;

    /** Statement lines listed at once. */
    private const MAX_LINES = 1000;

    public function __construct(
        private Books $books,
        private BankStatements $statements,
        private BankMatching $matching,
        private Reconciliations $reconciliations,
        private AccountingPresenter $presenter,
    ) {}

    /** Accounts that can be matched, with how much is waiting on each. */
    public function accounts(string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.view', $company);
        $this->books->assertSetUp($company);

        $accounts = $this->books->query(Account::class, $company)->where('type', AccountType::Asset->value)->where('is_group', false)->orderBy('code')->get()
            ->filter(fn (Account $account) => $account->isPostable());
        $waiting = $this->books->query(BankLine::class, $company)->whereNull('reconciliation_id')->get(['account_id', 'amount_minor', 'matched_minor'])
            ->groupBy('account_id')->map(fn ($lines) => ['open' => $lines->count(), 'unmatched' => $lines->filter(fn (BankLine $line) => ! $line->isMatched())->count()]);
        $latest = $this->books->query(Reconciliation::class, $company)->where('status', Reconciliation::FINISHED)->orderBy('statement_date')->get()->keyBy('account_id');

        return response()->json([
            'data' => $accounts->map(fn (Account $account) => [
                'id' => $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'open_lines' => $waiting->get($account->getKey())['open'] ?? 0,
                'unmatched_lines' => $waiting->get($account->getKey())['unmatched'] ?? 0,
                'reconciled_to' => $latest->get($account->getKey())?->statement_date?->toDateString(),
            ])->sortByDesc(fn (array $row) => $row['open_lines'] > 0 || $row['reconciled_to'] !== null)->values(),
        ]);
    }

    /**
     * One account's matching desk: statement lines not yet inside a finished
     * reconciliation (show=all: the latest ones), proposed pairs, book lines
     * the bank has not shown, the remembered file format and past reconciliations.
     */
    public function show(Request $request, string $organization, string $account): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.view', $company);
        $found = $this->statements->account($company, $account);
        $all = $request->query('show') === 'all';

        $lines = $this->books->query(BankLine::class, $company)->where('account_id', $found->getKey())
            ->when(! $all, fn ($query) => $query->whereNull('reconciliation_id'))
            ->orderByDesc('line_date')->orderByDesc('id')->limit(self::MAX_LINES)->get();
        $suggested = $this->matching->suggestions($company, $found, $lines);
        $matches = $this->books->query(BankMatch::class, $company)->whereIn('bank_line_id', $lines->pluck('id')->all())->get()->groupBy('bank_line_id');
        $journalLines = $this->journalLines($company, [...$matches->flatten(1)->pluck('journal_line_id')->all(), ...array_values($suggested)]);

        return response()->json([
            'data' => [
                'account' => ['id' => $found->getKey(), 'code' => $found->code, 'name' => $found->name],
                'currency' => $this->books->currency($company),
                'format' => $this->statements->format($company, $found),
                'lines' => $lines->map(fn (BankLine $line) => $this->presenter->bankLine(
                    $line,
                    ($matches[$line->getKey()] ?? collect())->map(fn (BankMatch $match) => $journalLines[$match->journal_line_id] ?? null)->filter()->values()->all(),
                    isset($suggested[$line->getKey()]) ? ($journalLines[$suggested[$line->getKey()]] ?? null) : null,
                ))->values(),
                'outstanding' => $this->matching->bookLines($company, $found),
                'reconciliations' => $this->books->query(Reconciliation::class, $company)->where('account_id', $found->getKey())
                    ->orderByDesc('statement_date')->orderByDesc('created_at')->limit(12)->get()
                    ->map(fn (Reconciliation $reconciliation) => $this->presenter->reconciliation($reconciliation))->values(),
            ],
            'meta' => [
                'can_reconcile' => Gate::allows('accounting.reconcile', $company),
                'can_post' => Gate::allows('accounting.post', $company),
            ],
        ]);
    }

    public function import(BankImportRequest $request, string $organization, string $account): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.reconcile', $company);
        $found = $this->statements->account($company, $account);

        $result = $this->statements->import($company, $found, $request->file('file'), $request->validated('columns'), $request->validated('date_format'), $request->user());

        return response()->json(['data' => $result], 201);
    }

    public function autoMatch(Request $request, string $organization, string $account): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.reconcile', $company);
        $found = $this->statements->account($company, $account);

        return response()->json(['data' => ['matched' => $this->matching->autoMatch($company, $found, $request->user())]]);
    }

    /** What finishing up to a day would show, before finishing. */
    public function preview(Request $request, string $organization, string $account): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.view', $company);
        $found = $this->statements->account($company, $account);
        $data = $request->validate((new ReconciliationRequest)->rules());

        return response()->json(['data' => $this->reconciliations->preview(
            $company, $found, $data['statement_date'], (int) $data['statement_balance_minor'], isset($data['opening_balance_minor']) ? (int) $data['opening_balance_minor'] : null,
        )]);
    }

    public function finish(ReconciliationRequest $request, string $organization, string $account): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.reconcile', $company);
        $found = $this->statements->account($company, $account);

        $reconciliation = $this->reconciliations->finish(
            $company, $found, $request->validated('statement_date'), (int) $request->validated('statement_balance_minor'),
            $request->validated('opening_balance_minor') === null ? null : (int) $request->validated('opening_balance_minor'), $request->user(),
        );

        return response()->json(['data' => $this->presenter->reconciliation($reconciliation)], 201);
    }

    public function reopen(Request $request, string $organization, string $reconciliation): JsonResponse
    {
        $company = $this->company($organization);
        $found = $this->books->query(Reconciliation::class, $company)->whereKey($reconciliation)->first() ?? throw AccountingException::reconciliationNotFound();
        Gate::authorize('accounting.reconcile', $company);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json(['data' => $this->presenter->reconciliation($this->reconciliations->reopen($company, $found, $data['reason'], $request->user()))]);
    }

    /** match, unmatch or entry on one statement line. */
    public function line(BankLineRequest $request, string $organization, string $line, string $step): JsonResponse
    {
        $company = $this->company($organization);
        $found = $this->lineIn($company, $line);
        Gate::authorize('accounting.reconcile', $company);
        $actor = $request->user();

        if ($step === 'entry') {
            Gate::authorize('accounting.post', $company);
            $journal = $this->matching->entry($company, $found, $request->safe()->only(['account_id', 'narration', 'cost_centre_id']), $actor);

            return response()->json(['data' => $this->presenter->journal($journal, $company)], 201);
        }

        $changed = match ($step) {
            'match' => $this->matching->match($company, $found, $request->validated('journal_line_ids'), $actor),
            'unmatch' => $this->matching->unmatch($company, $found, $actor),
            default => throw AccountingException::unknownStep(),
        };

        return response()->json(['data' => $this->presenter->bankLine($changed, [], null)]);
    }

    public function destroyLine(Request $request, string $organization, string $line): JsonResponse
    {
        $company = $this->company($organization);
        $found = $this->lineIn($company, $line);
        Gate::authorize('accounting.reconcile', $company);

        $this->statements->delete($company, $found, $request->user());

        return response()->json(null, 204);
    }

    private function lineIn(Organization $company, string $id): BankLine
    {
        return $this->books->query(BankLine::class, $company)->whereKey($id)->first() ?? throw AccountingException::bankLineNotFound();
    }

    /**
     * Book lines by id with their journal's number, date and words.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function journalLines(Organization $company, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->books->query(JournalLine::class, $company)
            ->join('acc_journals', 'acc_journals.id', '=', 'acc_journal_lines.journal_id')
            ->whereIn('acc_journal_lines.id', array_values(array_unique($ids)))
            ->select(['acc_journal_lines.id', 'acc_journal_lines.journal_id', 'acc_journal_lines.debit_minor', 'acc_journal_lines.credit_minor', 'acc_journals.number', 'acc_journals.entry_date', 'acc_journals.narration'])
            ->get()
            ->mapWithKeys(fn (JournalLine $line) => [(string) $line->id => [
                'id' => (string) $line->id,
                'journal_id' => (string) $line->journal_id,
                'number' => $line->number,
                'entry_date' => substr((string) $line->entry_date, 0, 10),
                'narration' => (string) $line->narration,
                'amount_minor' => $line->debit_minor - $line->credit_minor,
            ]])->all();
    }
}
