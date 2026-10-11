<?php

use App\Platform\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Modules\EducationFees\Events\BillIssued;
use Modules\EducationFees\Models\Bill;
use Modules\EducationFees\Models\Fine;

/*
 * FEE-1: student fees. Heads and structures (the most specific wins),
 * discounts (approval by someone else), sibling discounts, billing runs
 * (monthly, session, other; one bill per student and key), admission bills,
 * late fines (rule, nightly, waived), cancelling, isolation and module off.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-05 04:00:00', 'UTC'));
    // Setting up a school's fees takes more sensitive writes than one person may send a minute.
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'education', 'Test setup');
        toggles()->enable($group, 'education_fees', 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/education/{$path}";
    $this->fees = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/fees/{$path}";
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);

    $this->school = eduSchool($this);
    // A roomier section than the shared helper's.
    $this->sectionB = ($this->as)()->postJson(($this->api)('structure/sections'), ['unit_id' => $this->w->b1->id, 'session_id' => $this->school->session['id'], 'level_id' => $this->school->class6['id'], 'name' => 'B', 'capacity' => 40])->assertCreated()->json('data');
    $this->pupil = fn (string $name, string $phone) => newStudent($this, $this->school, [
        'name' => $name, 'guardians' => [['name' => "Parent of {$name}", 'phone' => $phone, 'relation' => 'father']],
        'enrollment' => ['session_id' => $this->school->session['id'], 'level_id' => $this->school->class6['id'], 'section_id' => $this->sectionB['id']],
    ])->assertCreated()->json('data');
    $this->head = fn (string $code, string $frequency, array $extra = []) => ($this->as)()->postJson(($this->fees)('heads'), [
        'code' => $code, 'name' => ['en' => "{$code} fee", 'bn' => "{$code} ফি"], 'frequency' => $frequency, 'income_key' => 'education_fees.tuition_income', ...$extra,
    ])->assertCreated()->json('data');
    $this->structure = function (array $lines, array $extra = []) {
        $made = ($this->as)()->postJson(($this->fees)('structures'), ['name' => 'Fees 2026', 'session_id' => $this->school->session['id'], 'lines' => $lines, ...$extra])->assertCreated()->json('data');

        return ($this->as)()->postJson(($this->fees)("structures/{$made['id']}/activate"), ['base_version' => $made['version']])->assertOk()->json('data');
    };
    $this->monthRun = fn (string $period, array $extra = []) => ($this->as)()->postJson(($this->fees)('runs'), [
        'kind' => 'monthly', 'session_id' => $this->school->session['id'], 'period' => $period, 'issue_date' => "{$period}-01", ...$extra,
    ]);
    $this->finalize = fn (array $run) => ($this->as)()->postJson(($this->fees)("runs/{$run['id']}/finalize"), ['base_version' => $run['version']])->assertOk()->json('data');
});

// ── Heads and structures ──

it('keeps fee heads and structures, the most specific structure winning', function () {
    $tuition = ($this->head)('TUI', 'monthly', ['sibling_discount' => true]);
    ($this->as)()->postJson(($this->fees)('heads'), ['code' => 'tui', 'name' => ['en' => 'Again'], 'frequency' => 'monthly', 'income_key' => 'education_fees.tuition_income'])
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);
    ($this->as)()->postJson(($this->fees)('heads'), ['code' => 'XYZ', 'name' => ['en' => 'X'], 'frequency' => 'monthly', 'income_key' => 'payroll.salary_expense'])
        ->assertUnprocessable()->assertJsonPath('code', 'invalid_income_key');

    $general = ($this->structure)([['head_id' => $tuition['id'], 'amount_minor' => 150000]]);
    expect($general)->toMatchArray(['status' => 'active', 'total_minor' => 150000]);
    // The same narrowing twice is refused; a class of its own is fine.
    $twin = ($this->as)()->postJson(($this->fees)('structures'), ['name' => 'Twin', 'session_id' => $this->school->session['id'], 'lines' => [['head_id' => $tuition['id'], 'amount_minor' => 1]]])->json('data');
    ($this->as)()->postJson(($this->fees)("structures/{$twin['id']}/activate"), ['base_version' => $twin['version']])->assertStatus(409)->assertJsonPath('code', 'structure_overlaps');
    ($this->structure)([['head_id' => $tuition['id'], 'amount_minor' => 120000]], ['name' => 'Class 6', 'level_id' => $this->school->class6['id']]);

    // A head in use keeps how often it is billed.
    ($this->as)()->patchJson(($this->fees)("heads/{$tuition['id']}"), ['frequency' => 'session', 'base_version' => $tuition['version']])->assertStatus(409)->assertJsonPath('code', 'head_in_use');
    ($this->as)()->patchJson(($this->fees)("heads/{$tuition['id']}"), ['name' => ['en' => 'Tuition', 'bn' => 'বেতন'], 'base_version' => $tuition['version']])->assertOk()->assertJsonPath('data.name.bn', 'বেতন');

    ($this->pupil)('Rahim', '01711-000001');
    $run = ($this->monthRun)('2026-01')->assertCreated()->json('data');
    expect($run)->toMatchArray(['status' => 'draft', 'bills_count' => 1, 'total_minor' => 120000, 'due_date' => '2026-01-10']);

    $setup = ($this->as)()->getJson(($this->fees)('setup'))->assertOk()->json('data');
    expect($setup['heads'])->toHaveCount(1)->and(collect($setup['income_keys'])->pluck('key'))->toContain('education_fees.tuition_income')
        ->and($setup['can'])->toMatchArray(['configure' => true, 'bill' => true])->and($setup['rules']['due_day'])->toBe(10);
});

// ── Billing runs ──

it('bills a month once per student, numbers the bills when final, and skips months a head is not billed in', function () {
    Event::fake([BillIssued::class]);
    $tuition = ($this->head)('TUI', 'monthly');
    $transport = ($this->head)('TRN', 'monthly', ['income_key' => 'education_fees.transport_income']);
    ($this->structure)([['head_id' => $tuition['id'], 'amount_minor' => 150000], ['head_id' => $transport['id'], 'amount_minor' => 50000, 'months' => [2, 3]]]);
    foreach (['Rahim' => '01711-000001', 'Karim' => '01711-000002', 'Salma' => '01711-000003'] as $name => $phone) {
        ($this->pupil)($name, $phone);
    }

    $january = ($this->monthRun)('2026-01')->assertCreated()->json('data');
    expect($january)->toMatchArray(['bills_count' => 3, 'total_minor' => 450000]);
    $shown = ($this->as)()->getJson(($this->fees)("runs/{$january['id']}"))->assertOk()->json('data');
    expect($shown['bills'])->toHaveCount(3)->and($shown['bills'][0])->toMatchArray(['status' => 'draft', 'number' => null, 'total_minor' => 150000]);
    // Drafts are nobody's bills yet.
    expect(($this->as)()->getJson(($this->fees)('bills'))->json('data'))->toBe([]);

    $final = ($this->finalize)($january);
    expect($final['status'])->toBe('final');
    $bills = ($this->as)()->getJson(($this->fees)('bills'))->json('data');
    expect(collect($bills)->pluck('number')->sort()->values()->all())->toBe(['FEE-2026-000001', 'FEE-2026-000002', 'FEE-2026-000003'])
        ->and(collect($bills)->pluck('status')->unique()->all())->toBe(['open']);
    Event::assertDispatchedTimes(BillIssued::class, 3);

    // January again: everyone is billed already.
    ($this->monthRun)('2026-01')->assertUnprocessable()->assertJsonPath('code', 'nothing_to_bill');
    // February brings transport; a month outside the session is refused.
    $february = ($this->monthRun)('2026-02')->assertCreated()->json('data');
    expect($february)->toMatchArray(['bills_count' => 3, 'total_minor' => 600000, 'due_date' => '2026-02-10']);
    ($this->monthRun)('2027-01')->assertUnprocessable()->assertJsonPath('code', 'period_outside_session');

    // A draft run is recalculated after a price change, or dropped.
    ($this->as)()->postJson(($this->fees)('structures'), ['name' => 'x', 'session_id' => $this->school->session['id'], 'level_id' => $this->school->class6['id'], 'lines' => [['head_id' => $tuition['id'], 'amount_minor' => 100000]]])->assertCreated();
    $refreshed = ($this->as)()->postJson(($this->fees)("runs/{$february['id']}/refresh"), ['base_version' => $february['version']])->assertOk()->json('data');
    expect($refreshed['total_minor'])->toBe(600000);
    ($this->as)()->postJson(($this->fees)("runs/{$february['id']}/cancel"), ['base_version' => $refreshed['version']])->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(Bill::query()->withoutGlobalScopes()->where('run_id', $february['id'])->count())->toBe(0);
    ($this->as)()->postJson(($this->fees)("runs/{$january['id']}/cancel"), ['base_version' => $final['version']])->assertStatus(409)->assertJsonPath('code', 'wrong_status');
});

it('bills session heads once a session and other heads only in an other run', function () {
    $tuition = ($this->head)('TUI', 'monthly');
    $session = ($this->head)('DEV', 'session');
    $exam = ($this->head)('EXM', 'other', ['income_key' => 'education_fees.exam_income']);
    ($this->structure)([['head_id' => $tuition['id'], 'amount_minor' => 150000], ['head_id' => $session['id'], 'amount_minor' => 300000], ['head_id' => $exam['id'], 'amount_minor' => 80000]]);
    ($this->pupil)('Rahim', '01711-000001');

    $once = ($this->as)()->postJson(($this->fees)('runs'), ['kind' => 'session', 'session_id' => $this->school->session['id'], 'issue_date' => '2026-01-05', 'due_date' => '2026-01-31'])->assertCreated()->json('data');
    expect($once['total_minor'])->toBe(300000);
    ($this->finalize)($once);
    ($this->as)()->postJson(($this->fees)('runs'), ['kind' => 'session', 'session_id' => $this->school->session['id'], 'issue_date' => '2026-01-05', 'due_date' => '2026-01-31'])
        ->assertUnprocessable()->assertJsonPath('code', 'nothing_to_bill');

    ($this->as)()->postJson(($this->fees)('runs'), ['kind' => 'other', 'session_id' => $this->school->session['id'], 'head_ids' => [$tuition['id']], 'issue_date' => '2026-01-05', 'due_date' => '2026-01-31'])
        ->assertUnprocessable()->assertJsonPath('code', 'head_not_for_run');
    $mid = ($this->as)()->postJson(($this->fees)('runs'), ['kind' => 'other', 'session_id' => $this->school->session['id'], 'head_ids' => [$exam['id']], 'issue_date' => '2026-01-05', 'due_date' => '2026-01-20'])->assertCreated()->json('data');
    $final = ($this->as)()->postJson(($this->fees)('runs'), ['kind' => 'other', 'session_id' => $this->school->session['id'], 'head_ids' => [$exam['id']], 'issue_date' => '2026-01-05', 'due_date' => '2026-01-20'])->assertCreated()->json('data');
    expect([$mid['total_minor'], $final['total_minor']])->toBe([80000, 80000]);
    ($this->as)()->postJson(($this->fees)('runs'), ['kind' => 'monthly', 'session_id' => $this->school->session['id'], 'issue_date' => '2026-01-05'])->assertUnprocessable()->assertJsonValidationErrors(['period']);
});

it('takes off approved discounts and the sibling discount, and shows how', function () {
    orgRule($this->w->c1, 'education_fees.sibling_discount_percent', 20);
    $tuition = ($this->head)('TUI', 'monthly', ['sibling_discount' => true]);
    $transport = ($this->head)('TRN', 'monthly', ['income_key' => 'education_fees.transport_income']);
    ($this->structure)([['head_id' => $tuition['id'], 'amount_minor' => 150000], ['head_id' => $transport['id'], 'amount_minor' => 50000]]);
    $rahim = ($this->pupil)('Rahim', '01711-000001');
    // Same guardian phone: Karim is Rahim's younger brother.
    $karim = ($this->pupil)('Karim', '01711-000001');
    $salma = ($this->pupil)('Salma', '01711-000003');

    // Any discount waits for someone else (approval above 0 % by default); never the one who asked.
    $half = ($this->as)()->postJson(($this->fees)('concessions'), ['student_id' => $salma['id'], 'head_id' => $tuition['id'], 'mode' => 'percent', 'percent_bp' => 5000, 'reason' => 'Merit scholarship', 'starts_on' => '2026-01-01'])
        ->assertCreated()->json('data');
    expect($half['status'])->toBe('pending');
    ($this->as)()->postJson(($this->fees)("concessions/{$half['id']}/approve"), ['base_version' => $half['version']])->assertForbidden()->assertJsonPath('code', 'own_approval');
    $approver = createMember($this->w->c1);
    $approverToken = orgToken($approver, $this->w->c1);
    ($this->as)($approverToken)->postJson(($this->fees)("concessions/{$half['id']}/approve"), ['base_version' => $half['version']])->assertOk()->assertJsonPath('data.status', 'active');
    // A fixed amount off every head, then a rejected one that changes nothing.
    $fixed = ($this->as)()->postJson(($this->fees)('concessions'), ['student_id' => $salma['id'], 'mode' => 'fixed', 'amount_minor' => 60000, 'reason' => 'Hardship', 'starts_on' => '2026-01-01'])->json('data');
    ($this->as)($approverToken)->postJson(($this->fees)("concessions/{$fixed['id']}/approve"), ['base_version' => $fixed['version']])->assertOk();
    $no = ($this->as)()->postJson(($this->fees)('concessions'), ['student_id' => $rahim['id'], 'mode' => 'percent', 'percent_bp' => 10000, 'reason' => 'Asked for all', 'starts_on' => '2026-01-01'])->json('data');
    ($this->as)($approverToken)->postJson(($this->fees)("concessions/{$no['id']}/reject"), ['base_version' => $no['version']])->assertUnprocessable()->assertJsonValidationErrors(['note']);
    ($this->as)($approverToken)->postJson(($this->fees)("concessions/{$no['id']}/reject"), ['base_version' => $no['version'], 'note' => 'Not eligible'])->assertOk()->assertJsonPath('data.status', 'rejected');

    $run = ($this->monthRun)('2026-01')->assertCreated()->json('data');
    $bills = collect(($this->as)()->getJson(($this->fees)("runs/{$run['id']}"))->json('data.bills'))->keyBy('student_id');
    // Rahim (eldest) pays all; Karim gets 20 % off tuition only; Salma 50 % off tuition, and 600.00 off line by line (transport first, by code).
    expect($bills[$rahim['id']])->toMatchArray(['gross_minor' => 200000, 'discount_minor' => 0, 'total_minor' => 200000])
        ->and($bills[$karim['id']])->toMatchArray(['discount_minor' => 30000, 'total_minor' => 170000])
        ->and($bills[$salma['id']])->toMatchArray(['discount_minor' => 135000, 'total_minor' => 65000]);
    $salmaBill = ($this->as)()->getJson(($this->fees)("bills/{$bills[$salma['id']]['id']}"))->assertOk()->json('data');
    $line = collect($salmaBill['lines'])->firstWhere('head_id', $tuition['id']);
    expect($line)->toMatchArray(['amount_minor' => 150000, 'discount_minor' => 85000, 'due_minor' => 65000])
        ->and($line['basis'])->toMatchArray(['percent_bp' => 5000, 'fixed_minor' => 10000])
        ->and(collect($salmaBill['lines'])->firstWhere('head_id', $transport['id']))->toMatchArray(['discount_minor' => 50000, 'due_minor' => 0])
        ->and(collect($salmaBill['heads'])->pluck('code')->sort()->values()->all())->toBe(['TRN', 'TUI']);

    // An ended discount stops for later bills.
    $active = ($this->as)()->getJson(($this->fees)("concessions?student_id={$salma['id']}&status=active"))->json('data');
    expect($active)->toHaveCount(2);
    foreach ($active as $concession) {
        ($this->as)()->postJson(($this->fees)("concessions/{$concession['id']}/end"), ['ends_on' => '2026-01-31', 'base_version' => $concession['version']])->assertOk()->assertJsonPath('data.status', 'ended');
    }
    ($this->finalize)($run);
    $february = ($this->monthRun)('2026-02')->json('data');
    $next = collect(($this->as)()->getJson(($this->fees)("runs/{$february['id']}"))->json('data.bills'))->keyBy('student_id');
    expect($next[$salma['id']]['total_minor'])->toBe(200000);
    expect(AuditLog::query()->where('action', 'education_fees.concession_approved')->count())->toBe(2);
});

it('lets small discounts through without approval when the rule allows it', function () {
    trustedOrgRule($this->w->c1, 'education_fees.concession_approval_above_percent', 25);
    $tuition = ($this->head)('TUI', 'monthly');
    $rahim = ($this->pupil)('Rahim', '01711-000001');
    $concede = fn (array $data) => ($this->as)()->postJson(($this->fees)('concessions'), ['student_id' => $rahim['id'], 'reason' => 'Staff child', 'starts_on' => '2026-01-01', ...$data])->json('data.status');

    expect($concede(['mode' => 'percent', 'percent_bp' => 2500]))->toBe('active')
        ->and($concede(['mode' => 'percent', 'percent_bp' => 2600]))->toBe('pending')
        ->and($concede(['mode' => 'fixed', 'amount_minor' => 100]))->toBe('pending');
    trustedOrgRule($this->w->c1, 'education_fees.concession_approval_above_percent', null);
    expect($concede(['mode' => 'fixed', 'amount_minor' => 100]))->toBe('active');
    ($this->as)()->postJson(($this->fees)('concessions'), ['student_id' => $rahim['id'], 'mode' => 'percent', 'reason' => 'x', 'starts_on' => '2026-01-01'])
        ->assertUnprocessable()->assertJsonValidationErrors(['percent_bp', 'reason']);
    expect($tuition['code'])->toBe('TUI');
});

// ── Admission, cancelling, fines ──

it('bills a new student the admission heads at once, when the rule allows it', function () {
    $admission = ($this->head)('ADM', 'admission', ['income_key' => 'education_fees.admission_income']);
    ($this->structure)([['head_id' => $admission['id'], 'amount_minor' => 500000]]);
    $rahim = ($this->pupil)('Rahim', '01711-000001');
    $statement = ($this->as)()->getJson(($this->fees)("students/{$rahim['id']}"))->assertOk()->json('data');
    expect($statement['bills'])->toHaveCount(1)->and($statement['bills'][0])->toMatchArray(['source' => 'admission', 'status' => 'open', 'total_minor' => 500000, 'due_date' => '2026-01-05'])
        ->and($statement['owed_minor'])->toBe(500000);

    orgRule($this->w->c1, 'education_fees.bill_on_admission', false);
    $karim = ($this->pupil)('Karim', '01711-000002');
    expect(($this->as)()->getJson(($this->fees)("students/{$karim['id']}"))->json('data.bills'))->toBe([]);
});

it('cancels an unpaid bill with a reason, so the month can be billed again', function () {
    $tuition = ($this->head)('TUI', 'monthly');
    ($this->structure)([['head_id' => $tuition['id'], 'amount_minor' => 150000]]);
    $rahim = ($this->pupil)('Rahim', '01711-000001');
    ($this->finalize)(($this->monthRun)('2026-01')->json('data'));
    $bill = ($this->as)()->getJson(($this->fees)("bills?student_id={$rahim['id']}"))->json('data.0');

    ($this->as)()->postJson(($this->fees)("bills/{$bill['id']}/cancel"), ['base_version' => $bill['version']])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    ($this->as)()->postJson(($this->fees)("bills/{$bill['id']}/cancel"), ['reason' => 'Wrong amount', 'base_version' => $bill['version']])->assertOk()
        ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.balance_minor', 0);
    $again = ($this->monthRun)('2026-01')->assertCreated()->json('data');
    expect($again['bills_count'])->toBe(1);

    expect($again['total_minor'])->toBe(150000);
});

it('adds late fines each night under the rule and stops them when waived', function () {
    trustedOrgRule($this->w->c1, 'education_fees.late_fine', ['enabled' => true, 'mode' => 'per_day', 'amount_minor' => 1000, 'grace_days' => 2, 'max_minor' => 2500]);
    $tuition = ($this->head)('TUI', 'monthly');
    $noFine = ($this->head)('LIB', 'monthly', ['late_fine' => false]);
    ($this->structure)([['head_id' => $tuition['id'], 'amount_minor' => 150000]]);
    $rahim = ($this->pupil)('Rahim', '01711-000001');
    ($this->finalize)(($this->monthRun)('2026-01')->json('data'));
    // Tokens expire over days: a fresh one at each reading.
    $bill = function () use ($rahim) {
        $this->token = orgToken($this->owner, $this->w->c1);

        return ($this->as)()->getJson(($this->fees)("bills?student_id={$rahim['id']}"))->json('data.0');
    };

    // Due 10 January, two days' grace: the first fine on the 13th.
    $this->travelTo(CarbonImmutable::parse('2026-01-12 04:00:00', 'UTC'));
    $this->artisan('education-fees:apply-fines')->assertSuccessful();
    expect($bill()['fine_minor'])->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-01-13 04:00:00', 'UTC'));
    $this->artisan('education-fees:apply-fines')->assertSuccessful();
    $this->artisan('education-fees:apply-fines')->assertSuccessful();
    expect($bill())->toMatchArray(['fine_minor' => 1000, 'total_minor' => 151000, 'overdue' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-01-20 04:00:00', 'UTC'));
    $this->artisan('education-fees:apply-fines')->assertSuccessful();
    expect($bill()['fine_minor'])->toBe(2500);

    $approver = createMember($this->w->c1);
    $token = orgToken($approver, $this->w->c1);
    $current = $bill();
    ($this->as)($token)->postJson(($this->fees)("bills/{$current['id']}/waive-fine"), ['reason' => 'Flood week', 'base_version' => $current['version']])->assertOk()
        ->assertJsonPath('data.fine_minor', 0)->assertJsonPath('data.total_minor', 150000)->assertJsonPath('data.fines_stopped', true);
    $this->travelTo(CarbonImmutable::parse('2026-01-25 04:00:00', 'UTC'));
    $this->artisan('education-fees:apply-fines')->assertSuccessful();
    expect($bill()['fine_minor'])->toBe(0)->and(Fine::query()->withoutGlobalScopes()->where('kind', 'waiver')->sum('amount_minor'))->toEqual(-2500);
    expect($noFine['late_fine'])->toBeFalse();
});

it('bills the month by itself on the chosen day when the institution asks for it', function () {
    $tuition = ($this->head)('TUI', 'monthly');
    ($this->structure)([['head_id' => $tuition['id'], 'amount_minor' => 150000]]);
    ($this->pupil)('Rahim', '01711-000001');
    $this->travelTo(CarbonImmutable::parse('2026-02-01 03:00:00', 'UTC'));
    $this->artisan('education-fees:auto-bill')->assertSuccessful();
    expect(Bill::query()->withoutGlobalScopes()->count())->toBe(0);

    orgRule($this->w->c1, 'education_fees.auto_monthly_billing', ['enabled' => true, 'day' => 1]);
    $this->artisan('education-fees:auto-bill')->assertSuccessful();
    $this->artisan('education-fees:auto-bill')->assertSuccessful();
    $bills = Bill::query()->withoutGlobalScopes()->get();
    expect($bills)->toHaveCount(1)->and($bills[0])->toMatchArray(['billing_key' => 'monthly:2026-02', 'status' => 'open']);
});

// ── Who may do what ──

it('keeps fees to the people allowed, inside their institution, and answers 403 while fees are off', function () {
    $tuition = ($this->head)('TUI', 'monthly');
    ($this->structure)([['head_id' => $tuition['id'], 'amount_minor' => 150000]]);
    $rahim = ($this->pupil)('Rahim', '01711-000001');
    ($this->finalize)(($this->monthRun)('2026-01')->json('data'));
    $bill = ($this->as)()->getJson(($this->fees)('bills'))->json('data.0');

    // Someone who only looks.
    $viewer = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education_fees.view', 'education.view']));
    $viewerToken = orgToken($viewer, $this->w->c1);
    ($this->as)($viewerToken)->getJson(($this->fees)("bills/{$bill['id']}"))->assertOk();
    ($this->as)($viewerToken)->postJson(($this->fees)('runs'), ['kind' => 'monthly', 'session_id' => $this->school->session['id'], 'period' => '2026-02', 'issue_date' => '2026-02-01'])->assertForbidden();
    ($this->as)($viewerToken)->postJson(($this->fees)("bills/{$bill['id']}/waive-fine"), ['reason' => 'x', 'base_version' => 1])->assertForbidden();
    ($this->as)($viewerToken)->postJson(($this->fees)('heads'), ['code' => 'X', 'name' => ['en' => 'X'], 'frequency' => 'monthly', 'income_key' => 'education_fees.tuition_income'])->assertForbidden();

    // Another institution and another partner's client see nothing of it.
    foreach ([$this->w->c2, $this->w->c4] as $other) {
        $stranger = createMember($other);
        $this->asToken(orgToken($stranger, $other))->getJson(($this->fees)("bills/{$bill['id']}", $other))->assertNotFound();
        $this->asToken(orgToken($stranger, $other))->getJson(($this->fees)("students/{$rahim['id']}", $other))->assertNotFound();
        expect($this->asToken(orgToken($stranger, $other))->getJson(($this->fees)('bills', $other))->json('data'))->toBe([]);
    }

    toggles()->disable($this->w->g1, 'education_fees', 'Test');
    ($this->as)()->getJson(($this->fees)('bills'))->assertForbidden();
    expect(Bill::query()->withoutGlobalScopes()->count())->toBe(1);
});
