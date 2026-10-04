<?php

use App\Platform\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Modules\Accounting\Services\BankStatements;

/*
 * ACC-4c: bank, wallet and cash statements brought in from CSV, matched with
 * the books (proposed pairs, by hand, or a missing entry written from the
 * line), and reconciled up to a day; finished lines stay locked until the
 * latest reconciliation is reopened with a reason.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 06:00:00', 'UTC'));
    $this->w = accountingWorld();
    setUpBooks($this, $this->w->token, $this->w->c1)->assertCreated();
    trustedOrgRule($this->w->c1, 'accounting.allow_backdated_entries_days', 60);
    $this->bank = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['accounting.view', 'accounting.post', 'accounting.reconcile'], 'Bank clerk')), $this->w->c1);
    $this->account = accountId($this->w->c1, '1120');
    $this->api = fn (string $path) => "/api/organizations/{$this->w->c1->id}/accounting/{$path}";
    $this->desk = fn (?string $token = null) => $this->asToken($token ?? $this->bank)->getJson(($this->api)("bank/accounts/{$this->account}"))->assertOk()->json('data');

    // The books: money in on the 2nd, rent paid on the 5th, a cheque written on the 10th the bank has not paid yet.
    $post = fn (array $lines, string $date) => journalVia($this, $this->w->token, $this->w->c1, $lines, ['submit' => true, 'entry_date' => $date])->assertCreated();
    $post([['1120', 50000, 0], ['4100', 0, 50000]], '2026-10-02');
    $post([['5300', 20000, 0], ['1120', 0, 20000]], '2026-10-05');
    $post([['5500', 3000, 0], ['1120', 0, 3000]], '2026-10-10');

    $this->csv = "Date,Narration,Ref,Withdrawal,Deposit\n01/10/2026,Opening balance,,,\n03/10/2026,Cash deposit,DP1,,\"500.00\"\n06/10/2026,Rent October,CHQ 1001,200.00,\n09/10/2026,Bank charge,,5.75,\n";
    $this->import = fn (?string $csv = null, array $data = [], ?string $token = null) => $this->asToken($token ?? $this->bank)->post(($this->api)("bank/accounts/{$this->account}/import"), [
        'file' => UploadedFile::fake()->createWithContent('statement.csv', $csv ?? $this->csv),
        'columns' => ['date' => 'Date', 'description' => 'Narration', 'reference' => 'Ref', 'money_in' => 'Deposit', 'money_out' => 'Withdrawal'],
        'date_format' => 'd/m/Y',
        ...$data,
    ], ['Accept' => 'application/json']);
});

it('brings a statement in once, skipping balance rows, and remembers its columns', function () {
    ($this->import)()->assertCreated()->assertJsonPath('data', ['added' => 3, 'skipped' => 1]);
    ($this->import)()->assertCreated()->assertJsonPath('data', ['added' => 0, 'skipped' => 4]);

    $desk = ($this->desk)();
    expect(collect($desk['lines'])->pluck('amount_minor')->all())->toBe([-575, -20000, 50000])
        ->and($desk['lines'][2])->toMatchArray(['line_date' => '2026-10-03', 'description' => 'Cash deposit', 'reference' => 'DP1', 'status' => 'unmatched'])
        ->and($desk['format'])->toMatchArray(['date_format' => 'd/m/Y'])
        ->and($desk['format']['columns']['money_in'])->toBe('Deposit');
    expect(AuditLog::query()->where('action', 'accounting.bank_imported')->count())->toBe(2);

    // Nothing comes in when a line is wrong; the person is told which.
    ($this->import)("Date,Narration,Ref,Withdrawal,Deposit\n2026-10-11,Fee,,1.00,\n")->assertUnprocessable()->assertJsonValidationErrors('file');
    ($this->import)(null, ['columns' => ['date' => 'Day', 'amount' => 'Amount']])->assertUnprocessable()->assertJsonValidationErrors(['columns.date', 'columns.amount']);
    ($this->import)(null, ['columns' => ['date' => 'Date']])->assertUnprocessable()->assertJsonValidationErrors('columns.amount');
    ($this->import)("\xFF\xFE\x00D")->assertUnprocessable()->assertJsonValidationErrors('file');
    expect(($this->desk)()['lines'])->toHaveCount(3);
});

it('reads amounts as banks write them, without floats', function () {
    expect(BankStatements::amount('(1,250.50)', 'BDT'))->toBe(-125050)
        ->and(BankStatements::amount('১,২৫০', 'BDT'))->toBe(125000)
        ->and(BankStatements::amount('Tk 1,250.5', 'BDT'))->toBe(125050)
        ->and(BankStatements::amount('-0.75', 'BDT'))->toBe(-75)
        ->and(BankStatements::amount('99.999', 'BDT'))->toBeNull()
        ->and(BankStatements::amount('abc', 'BDT'))->toBeNull();
});

it('proposes pairs, matches by hand and writes the entry the books are missing', function () {
    ($this->import)()->assertCreated();
    $desk = ($this->desk)();
    [$charge, $rent, $deposit] = $desk['lines'];
    expect($deposit['suggestion']['amount_minor'])->toBe(50000)
        ->and($rent['suggestion']['amount_minor'])->toBe(-20000)
        ->and($charge['suggestion'])->toBeNull()
        ->and($desk['outstanding'])->toHaveCount(3);

    $this->asToken($this->bank)->postJson(($this->api)("bank/accounts/{$this->account}/auto-match"))->assertOk()->assertJsonPath('data.matched', 2);
    $this->asToken($this->bank)->postJson(($this->api)("bank-lines/{$rent['id']}/unmatch"))->assertOk()->assertJsonPath('data.status', 'unmatched');
    $cheque = collect(($this->desk)()['outstanding'])->firstWhere('amount_minor', -3000);
    $this->asToken($this->bank)->postJson(($this->api)("bank-lines/{$rent['id']}/match"), ['journal_line_ids' => [$cheque['id']]])
        ->assertUnprocessable()->assertJsonValidationErrors('journal_line_ids');
    $rentBook = collect(($this->desk)()['outstanding'])->firstWhere('amount_minor', -20000);
    $this->asToken($this->bank)->postJson(($this->api)("bank-lines/{$rent['id']}/match"), ['journal_line_ids' => [$rentBook['id']]])->assertOk()->assertJsonPath('data.status', 'matched');

    // The bank charge: an entry dated the statement day (older than the backdating window allows people).
    trustedOrgRule($this->w->c1, 'accounting.allow_backdated_entries_days', 0);
    $this->asToken($this->bank)->postJson(($this->api)("bank-lines/{$charge['id']}/entry"), ['account_id' => $this->account, 'narration' => 'Bank charge'])
        ->assertUnprocessable()->assertJsonValidationErrors('account_id');
    $entry = $this->asToken($this->bank)->postJson(($this->api)("bank-lines/{$charge['id']}/entry"), ['account_id' => accountId($this->w->c1, '5800'), 'narration' => 'Bank charge September'])
        ->assertCreated()->json('data');
    expect($entry)->toMatchArray(['status' => 'posted', 'entry_date' => '2026-10-09']);

    $after = ($this->desk)();
    expect(collect($after['lines'])->pluck('status')->unique()->all())->toBe(['matched'])
        ->and(collect($after['outstanding'])->pluck('amount_minor')->all())->toBe([-3000])
        ->and($after['lines'][0]['matches'][0]['journal_id'])->toBe($entry['id']);
});

it('reconciles up to a day when the statement agrees, and reopens only the latest', function () {
    ($this->import)()->assertCreated();
    $this->asToken($this->bank)->postJson(($this->api)("bank/accounts/{$this->account}/auto-match"))->assertOk();
    $charge = ($this->desk)()['lines'][0];
    $url = ($this->api)("bank/accounts/{$this->account}/reconciliations");

    $this->asToken($this->bank)->postJson($url, ['statement_date' => '2026-10-09', 'statement_balance_minor' => 29425])
        ->assertUnprocessable()->assertJsonValidationErrors('opening_balance_minor');
    $preview = $this->asToken($this->w->viewerToken)->getJson(($this->api)("bank/accounts/{$this->account}/reconciliation?statement_date=2026-10-09&statement_balance_minor=29425&opening_balance_minor=0"))
        ->assertOk()->json('data');
    expect($preview)->toMatchArray([
        'lines' => 3, 'money_in_minor' => 50000, 'money_out_minor' => 20575, 'cleared_balance_minor' => 29425,
        'difference_minor' => 0, 'unmatched' => 1, 'book_balance_minor' => 30000, 'can_finish' => false,
    ]);
    $this->asToken($this->bank)->postJson($url, ['statement_date' => '2026-10-09', 'statement_balance_minor' => 29425, 'opening_balance_minor' => 0])
        ->assertConflict()->assertJsonPath('code', 'bank_lines_unmatched');

    $this->asToken($this->bank)->postJson(($this->api)("bank-lines/{$charge['id']}/entry"), ['account_id' => accountId($this->w->c1, '5800'), 'narration' => 'Bank charge'])->assertCreated();
    $this->asToken($this->bank)->postJson($url, ['statement_date' => '2026-10-09', 'statement_balance_minor' => 30000, 'opening_balance_minor' => 0])
        ->assertConflict()->assertJsonPath('code', 'statement_difference');
    $done = $this->asToken($this->bank)->postJson($url, ['statement_date' => '2026-10-09', 'statement_balance_minor' => 29425, 'opening_balance_minor' => 0])
        ->assertCreated()->json('data');
    expect($done)->toMatchArray(['status' => 'finished', 'book_balance_minor' => 29425, 'statement_balance_minor' => 29425]);

    // Locked lines; later files skip what is already reconciled; the next period starts from this balance.
    $this->asToken($this->bank)->postJson(($this->api)("bank-lines/{$charge['id']}/unmatch"))->assertConflict()->assertJsonPath('code', 'bank_line_reconciled');
    $this->asToken($this->bank)->deleteJson(($this->api)("bank-lines/{$charge['id']}"))->assertConflict()->assertJsonPath('code', 'bank_line_reconciled');
    ($this->import)("Date,Narration,Ref,Withdrawal,Deposit\n09/10/2026,Late duplicate,,1.00,\n12/10/2026,Cheque 1002,,30.00,\n")->assertCreated()->assertJsonPath('data', ['added' => 1, 'skipped' => 1]);
    $this->asToken($this->bank)->getJson(($this->api)("bank/accounts/{$this->account}/reconciliation?statement_date=2026-10-12&statement_balance_minor=26425"))
        ->assertOk()->assertJsonPath('data.opening_balance_minor', 29425)->assertJsonPath('data.difference_minor', 0)->assertJsonPath('data.first', false);
    $this->asToken($this->bank)->getJson(($this->api)("bank/accounts/{$this->account}/reconciliation?statement_date=2026-10-08&statement_balance_minor=0"))
        ->assertUnprocessable()->assertJsonValidationErrors('statement_date');

    $reopen = ($this->api)("reconciliations/{$done['id']}/reopen");
    $this->asToken($this->bank)->postJson($reopen)->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->asToken($this->bank)->postJson($reopen, ['reason' => 'Bank corrected the charge'])->assertOk()->assertJsonPath('data.status', 'reopened');
    $this->asToken($this->bank)->postJson($reopen, ['reason' => 'Twice'])->assertConflict()->assertJsonPath('code', 'not_latest_reconciliation');
    $this->asToken($this->bank)->postJson(($this->api)("bank-lines/{$charge['id']}/unmatch"))->assertOk();
    expect(AuditLog::query()->where('action', 'accounting.reconciliation_reopened')->sole()->reason)->toBe('Bank corrected the charge');
});

it('keeps statements to the company, its money accounts and people with the right', function () {
    ($this->import)(null, [], $this->w->viewerToken)->assertForbidden();
    ($this->import)(null, [], $this->w->token)->assertForbidden();
    ($this->import)()->assertCreated();
    ($this->desk)($this->w->viewerToken);
    $line = ($this->desk)()['lines'][0];
    $this->asToken($this->w->viewerToken)->postJson(($this->api)("bank-lines/{$line['id']}/unmatch"))->assertForbidden();
    $this->asToken($this->bank)->getJson(($this->api)('bank/accounts/'.accountId($this->w->c1, '4100')))->assertUnprocessable()->assertJsonPath('code', 'not_a_money_account');
    expect(collect($this->asToken($this->w->viewerToken)->getJson(($this->api)('bank/accounts'))->assertOk()->json('data'))->firstWhere('code', '1120'))
        ->toMatchArray(['open_lines' => 3, 'unmatched_lines' => 3]);

    $c2Token = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['accounting.view', 'accounting.reconcile'], 'Bank')), $this->w->c2);
    $this->asToken($c2Token)->getJson(($this->api)("bank/accounts/{$this->account}"))->assertNotFound();
    $this->asToken($c2Token)->deleteJson(($this->api)("bank-lines/{$line['id']}"))->assertNotFound();

    $this->asToken($this->bank)->deleteJson(($this->api)("bank-lines/{$line['id']}"))->assertNoContent();
    toggles()->disable($this->w->g1, 'accounting', 'Test setup', confirm: true);
    $this->asToken($this->bank)->getJson(($this->api)("bank/accounts/{$this->account}"))->assertForbidden();
});
