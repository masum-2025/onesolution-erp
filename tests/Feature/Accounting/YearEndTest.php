<?php

use App\Platform\Audit\AuditLog;
use Carbon\CarbonImmutable;

/*
 * ACC-4b: closing a fiscal year into retained earnings (in order, once its
 * months are closed), and reopening it only with a reason and a second
 * person; profit and loss never sees the closing entry.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 06:00:00', 'UTC'));
    $this->w = accountingWorld();
    setUpBooks($this, $this->w->token, $this->w->c1)->assertCreated();
    $this->api = fn (string $path) => "/api/organizations/{$this->w->c1->id}/accounting/{$path}";
    $this->years = fn () => collect($this->asToken($this->w->viewerToken)->getJson(($this->api)('fiscal-years'))->assertOk()->json('data'))->sortBy('starts_on')->values();
    $this->close = fn (array $year, ?string $token = null) => $this->asToken($token ?? $this->w->approverToken)->postJson(($this->api)("fiscal-years/{$year['id']}/close"), ['base_version' => $year['version']]);
    $this->row = fn (string $code, string $asOf = '2027-06-30') => collect($this->asToken($this->w->viewerToken)->getJson(($this->api)("reports/trial-balance?as_of={$asOf}"))->json('data.rows'))->firstWhere('code', $code);

    // A year with a profit of 700.00: sales 1,000.00 and supplies 300.00, in two branches' worth of lines.
    journalVia($this, $this->w->token, $this->w->c1, [['1110', 100000, 0], ['4100', 0, 100000]], ['submit' => true])->assertCreated();
    journalVia($this, $this->w->token, $this->w->c1, [['5500', 30000, 0], ['1110', 0, 30000]], ['submit' => true])->assertCreated();
    $this->secondCloser = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['accounting.view', 'accounting.close'], 'Controller')), $this->w->c1);
});

it('closes a year into retained earnings, keeping profit and loss as it was', function () {
    $year = ($this->years)()->first();
    ($this->close)($year, $this->w->token)->assertForbidden();
    ($this->close)($year)->assertConflict()->assertJsonPath('code', 'periods_still_open');

    trustedOrgRule($this->w->c1, 'accounting.year_close_requires_all_periods', false);
    ($this->close)([...$year, 'version' => 99])->assertConflict()->assertJsonPath('code', 'version_conflict');
    $closed = ($this->close)($year)->assertOk()->json('data');
    expect($closed)->toMatchArray(['status' => 'closed', 'can' => ['close' => false, 'reopen' => true, 'approve_reopen' => false, 'reject_reopen' => false]])
        ->and(collect($closed['periods'])->pluck('status')->unique()->all())->toBe(['closed'])
        ->and($closed['periods'])->toHaveCount(12);

    // Income and expenses are emptied into retained earnings; balances stay.
    expect(($this->row)('4100'))->toBeNull()
        ->and(($this->row)('5500'))->toBeNull()
        ->and(($this->row)('3200')['credit_minor'])->toBe(70000)
        ->and(($this->row)('1110')['debit_minor'])->toBe(70000)
        ->and(($this->row)('4100', '2027-06-29')['credit_minor'])->toBe(100000);

    $pl = $this->asToken($this->w->viewerToken)->getJson(($this->api)('reports/profit-loss?from=2026-07-01&to=2027-06-30'))->assertOk()->json('data');
    expect($pl['net_profit_minor'])->toBe(70000);
    $june = $this->asToken($this->w->viewerToken)->getJson(($this->api)('reports/profit-loss?from=2027-06-15&to=2027-06-30'))->json('data');
    expect($june['income']['rows'])->toBe([]);

    $sheet = $this->asToken($this->w->viewerToken)->getJson(($this->api)('reports/balance-sheet?as_of=2027-06-30'))->json('data');
    expect($sheet['earnings_to_date_minor'])->toBe(0)
        ->and($sheet['equity']['total_minor'])->toBe(70000)
        ->and($sheet['total_liabilities_and_equity_minor'])->toBe($sheet['assets']['total_minor']);

    // The ledger shows the closing line, ending the year at nothing.
    $ledger = $this->asToken($this->w->viewerToken)->getJson(($this->api)('reports/ledger?account_id='.accountId($this->w->c1, '4100').'&from=2026-07-01&to=2027-06-30'))->assertOk()->json('data');
    expect($ledger['closing_minor'])->toBe(0)->and($ledger['rows'])->toHaveCount(2);

    // Nothing more is posted into the closed year; the next year was added.
    journalVia($this, $this->w->token, $this->w->c1, [['1110', 1000, 0], ['4100', 0, 1000]], ['submit' => true])->assertUnprocessable()->assertJsonPath('code', 'period_closed');
    expect(($this->years)()->pluck('name')->all())->toBe(['2026-27', '2027-28'])
        ->and(AuditLog::query()->where('action', 'accounting.year_closed')->sole()->new_values)->toMatchArray(['net_profit_minor' => 70000]);
    ($this->close)([...$closed])->assertConflict()->assertJsonPath('code', 'year_already_closed');
});

it('closes years in order and reopens only the latest closed one', function () {
    trustedOrgRule($this->w->c1, 'accounting.year_close_requires_all_periods', false);
    $this->asToken($this->w->token)->postJson(($this->api)('fiscal-years'))->assertCreated();
    [$first, $second] = ($this->years)()->all();

    ($this->close)($second)->assertConflict()->assertJsonPath('code', 'earlier_year_open');
    ($this->close)($first)->assertOk();
    ($this->close)(($this->years)()[1])->assertOk();
    expect(($this->years)())->toHaveCount(3);

    $this->asToken($this->w->approverToken)->postJson(($this->api)("fiscal-years/{$first['id']}/reopen"), ['reason' => 'Audit adjustment'])
        ->assertConflict()->assertJsonPath('code', 'later_year_closed');
    // A month of a closed year stays shut until the year reopens.
    $this->asToken($this->w->approverToken)->postJson(($this->api)("periods/{$first['periods'][0]['id']}/reopen"), ['reason' => 'Late supplier bill'])
        ->assertConflict()->assertJsonPath('code', 'year_closed');
});

it('reopens a year with a reason and a second person, reversing the closing entry', function () {
    trustedOrgRule($this->w->c1, 'accounting.year_close_requires_all_periods', false);
    $year = ($this->years)()->first();
    ($this->close)($year)->assertOk();
    $url = ($this->api)("fiscal-years/{$year['id']}/reopen");

    $this->asToken($this->w->approverToken)->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('reason');
    $asked = $this->asToken($this->w->approverToken)->postJson($url, ['reason' => 'Auditor found a missing bill'])->assertOk()->json('data');
    expect($asked)->toMatchArray(['status' => 'closed'])
        ->and($asked['reopen_request'])->toMatchArray(['reason' => 'Auditor found a missing bill', 'mine' => true])
        ->and($asked['can'])->toMatchArray(['approve_reopen' => false, 'reject_reopen' => true]);
    $this->asToken($this->w->approverToken)->postJson($url, ['reason' => 'Asking twice'])->assertConflict()->assertJsonPath('code', 'reopen_already_asked');

    $request = ($this->api)("reopen-requests/{$asked['reopen_request']['id']}");
    $this->asToken($this->w->approverToken)->postJson("{$request}/approve")->assertForbidden()->assertJsonPath('code', 'own_reopen_request');
    $this->asToken($this->w->token)->postJson("{$request}/approve")->assertForbidden();
    $open = $this->asToken($this->secondCloser)->postJson("{$request}/approve")->assertOk()->json('data');
    expect($open)->toMatchArray(['status' => 'open', 'reopen_request' => null])
        ->and(($this->row)('4100')['credit_minor'])->toBe(100000)
        ->and(($this->row)('3200'))->toBeNull()
        ->and(AuditLog::query()->where('action', 'accounting.year_reopened')->sole()->reason)->toBe('Auditor found a missing bill');
    $this->asToken($this->secondCloser)->postJson("{$request}/approve")->assertConflict()->assertJsonPath('code', 'reopen_not_pending');

    // Months stay closed until someone reopens the one they need; closing again does not count twice.
    $this->asToken($this->w->approverToken)->postJson(($this->api)("periods/{$year['periods'][3]['id']}/reopen"), ['reason' => 'Missing bill'])->assertOk();
    journalVia($this, $this->w->token, $this->w->c1, [['5500', 10000, 0], ['1110', 0, 10000]], ['submit' => true])->assertCreated();
    ($this->close)(($this->years)()->first())->assertOk();
    expect(($this->row)('3200')['credit_minor'])->toBe(60000)
        ->and(($this->row)('5500'))->toBeNull();
});

it('lets the person who asked take a request back, and one-person books reopen at once', function () {
    trustedOrgRule($this->w->c1, 'accounting.year_close_requires_all_periods', false);
    $year = ($this->years)()->first();
    ($this->close)($year)->assertOk();

    $asked = $this->asToken($this->w->approverToken)->postJson(($this->api)("fiscal-years/{$year['id']}/reopen"), ['reason' => 'Wrong year chosen'])->json('data');
    $this->asToken($this->w->approverToken)->postJson(($this->api)("reopen-requests/{$asked['reopen_request']['id']}/reject"), ['note' => 'Asked by mistake'])
        ->assertOk()->assertJsonPath('data.reopen_request', null)->assertJsonPath('data.status', 'closed');
    expect(AuditLog::query()->where('action', 'accounting.year_reopen_rejected')->sole()->reason)->toBe('Asked by mistake');

    trustedOrgRule($this->w->c1, 'accounting.year_reopen_needs_second_person', false);
    $this->asToken($this->w->approverToken)->postJson(($this->api)("fiscal-years/{$year['id']}/reopen"), ['reason' => 'Only me keeps these books'])
        ->assertOk()->assertJsonPath('data.status', 'open');
});

it('keeps closing out of other companies and away from people without the right', function () {
    $year = ($this->years)()->first();
    $this->asToken($this->w->viewerToken)->postJson(($this->api)("fiscal-years/{$year['id']}/close"), ['base_version' => 1])->assertForbidden();

    $c2Token = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['accounting.view', 'accounting.close'], 'Closer')), $this->w->c2);
    $this->asToken($c2Token)->postJson("/api/organizations/{$this->w->c2->id}/accounting/fiscal-years/{$year['id']}/close", ['base_version' => 1])->assertNotFound();
    $this->asToken($c2Token)->postJson("/api/organizations/{$this->w->c1->id}/accounting/fiscal-years/{$year['id']}/close", ['base_version' => 1])->assertNotFound();
});
