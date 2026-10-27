<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Notifications\Models\NotificationDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Expenses\Models\Advance;

/*
 * EXP-3: advances (asked for within the rule's limit, decided like claims,
 * paid out, settled by claims, by handing back what is left or from a
 * month's salary through Payroll's DeductionSource) and per-diem lines
 * (days at a daily rate of the rule, no receipt). Company C1; today is
 * Wednesday 28 October 2026.
 */

beforeEach(function () {
    Queue::fake(); // keep messages queued: a sent message's values are wiped after delivery
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-28 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'expenses', 'Test setup');
    }
    $c1 = $this->w->c1;
    $this->admin = orgToken(staffWithRoles($c1, makeRole($c1, ['hrm.view', 'expenses.manage'], 'Expenses admin')), $c1);
    $this->bossUser = staffWithRoles($c1, makeRole($c1, ['expenses.approve', 'expenses.claim'], 'Manager'));
    $this->boss = orgToken($this->bossUser, $c1);
    $this->financeUser = staffWithRoles($c1, makeRole($c1, ['expenses.pay'], 'Finance'));
    $this->finance = orgToken($this->financeUser, $c1);
    $this->workerUser = staffWithRoles($c1, makeRole($c1, ['expenses.claim'], 'Worker'));
    $this->worker = orgToken($this->workerUser, $c1);

    $hire = fn (array $data) => hireVia($this, $this->w->token, $this->w->b1, ['joined_on' => '2026-01-01', ...$data])->assertCreated()->json('data');
    $link = fn (array $employee, User $user) => $this->asToken($this->w->token)
        ->putJson("/api/organizations/{$c1->id}/hrm/employees/{$employee['id']}/login", ['base_version' => $employee['version'], 'user_id' => $user->id])->assertOk();
    $this->mahmud = $hire(['full_name' => 'Mahmud Hasan']);
    $link($this->mahmud, $this->bossUser);
    $this->rahima = $hire(['full_name' => 'Rahima Akter', 'manager_id' => $this->mahmud['id']]);
    $link($this->rahima, $this->workerUser);

    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $c1)->id."/expenses/{$path}";
    $this->asToken($this->admin)->postJson(($this->api)('categories/defaults'))->assertOk();
    $this->dailyKind = $this->asToken($this->admin)->postJson(($this->api)('categories'), ['code' => 'DAILY', 'name' => ['en' => 'Daily allowance'], 'posting_key' => 'expenses.travel', 'per_diem' => true])
        ->assertCreated()->json('data');
    $this->kinds = collect($this->asToken($this->admin)->getJson(($this->api)('categories'))->json('data'))->keyBy('code');

    // An advance asked for, approved by the manager and paid out in cash.
    $this->ask = fn (int $amount, array $data = [], ?string $token = null) => $this->asToken($token ?? $this->worker)
        ->postJson(($this->api)('me/advances'), ['amount_minor' => $amount, 'purpose' => 'Trip to Chattogram', ...$data]);
    $this->step = fn (array $advance, string $step, string $token, array $data = []) => $this->asToken($token)
        ->postJson(($this->api)("advances/{$advance['id']}/{$step}"), ['base_version' => $advance['version'], ...$data]);
    $this->paidAdvance = function (int $amount) {
        $advance = ($this->ask)($amount)->assertCreated()->json('data');
        $advance = ($this->step)($advance, 'approve', $this->boss)->assertOk()->json('data');
        // Above expenses.second_approval_above the finance people approve too.
        if ($advance['status'] === 'submitted') {
            $advance = ($this->step)($advance, 'approve', $this->finance)->assertOk()->json('data');
        }

        return ($this->step)($advance, 'pay', $this->finance, ['method' => 'cash'])->assertOk()->json('data');
    };
    // A claim of daily allowances (no receipt needed), sent and approved.
    $this->approvedClaim = function (int $days, ?string $advanceId = null) {
        $claim = $this->asToken($this->worker)->postJson(($this->api)('me/claims'), [
            'title' => 'Trip to Chattogram', 'advance_id' => $advanceId,
            'lines' => [['spent_on' => '2026-10-20', 'category_id' => $this->dailyKind['id'], 'description' => 'Site visit', 'per_diem_code' => 'OUTSIDE', 'days' => $days]],
        ])->assertCreated()->json('data');
        $claim = $this->asToken($this->worker)->postJson(($this->api)("me/claims/{$claim['id']}/submit"), ['base_version' => $claim['version']])->assertOk()->json('data');

        $claim = $this->asToken($this->boss)->postJson(($this->api)("claims/{$claim['id']}/approve"), ['base_version' => $claim['version']])->assertOk()->json('data');

        return $claim['status'] === 'submitted'
            ? $this->asToken($this->finance)->postJson(($this->api)("claims/{$claim['id']}/approve"), ['base_version' => $claim['version']])->assertOk()->json('data')
            : $claim;
    };
    $this->books = function () {
        toggles()->enable($this->w->g1, 'accounting', 'Test setup');
        $books = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['accounting.view', 'accounting.manage'], 'Books')), $this->w->c1);
        setUpBooks($this, $books, $this->w->c1)->assertCreated();

        return fn (string $code) => collect($this->asToken($books)->getJson("/api/organizations/{$this->w->c1->id}/accounting/reports/trial-balance?as_of=2026-10-31")->json('data.rows'))->firstWhere('code', $code);
    };
});

it('pays daily allowances by days at the rule\'s rate, without receipts', function () {
    $draft = fn (array $line) => $this->asToken($this->worker)->postJson(($this->api)('me/claims'), [
        'title' => 'Trip', 'lines' => [['spent_on' => '2026-10-20', 'category_id' => $this->dailyKind['id'], 'description' => 'Site visit', ...$line]],
    ]);
    $draft(['per_diem_code' => 'MOON', 'days' => 2])->assertUnprocessable()->assertJsonValidationErrors('lines.0.per_diem_code');
    $draft(['per_diem_code' => 'OUTSIDE'])->assertUnprocessable()->assertJsonValidationErrors('lines.0.days');
    $claim = $draft(['per_diem_code' => 'OUTSIDE', 'days' => 3])->assertCreated()->json('data');
    // 3 days at 1,000.00 a day; above the receipt limit, yet sent without one.
    expect($claim['total_minor'])->toBe(300000)->and($claim['lines'][0])->toMatchArray(['per_diem' => true, 'per_diem_code' => 'OUTSIDE', 'days' => 3]);
    $this->asToken($this->worker)->postJson(($this->api)("me/claims/{$claim['id']}/submit"), ['base_version' => $claim['version']])->assertOk()->assertJsonPath('data.status', 'submitted');

    $me = $this->asToken($this->worker)->getJson(($this->api)('me'))->assertOk()->json('data');
    expect(collect($me['per_diem_rates'])->pluck('code')->all())->toBe(['CITY', 'OUTSIDE']);
    // A kind is never both by the day and by the kilometre.
    $both = $this->asToken($this->admin)->postJson(($this->api)('categories'), ['code' => 'BOTH', 'name' => ['en' => 'Both'], 'posting_key' => 'expenses.travel', 'per_diem' => true, 'mileage' => true])->json('data');
    expect($both)->toMatchArray(['mileage' => true, 'per_diem' => false]);
});

it('asks for an advance within the limit, decided like a claim and paid out into the books', function () {
    $row = ($this->books)();
    ($this->ask)(5000001)->assertUnprocessable()->assertJsonValidationErrors('amount_minor');
    $advance = ($this->ask)(1000000, ['op_id' => 'adv-1'])->assertCreated()->json('data');
    expect($advance)->toMatchArray(['status' => 'submitted', 'number' => 'ADV-2026-00001', 'steps_needed' => 2, 'outstanding_minor' => 0])
        ->and(($this->ask)(1, ['op_id' => 'adv-1'])->json('data.id'))->toBe($advance['id'])
        ->and(NotificationDelivery::query()->where('notification_key', 'expenses.advance_submitted')->pluck('user_id')->all())->toBe([$this->bossUser->id]);
    // What is asked counts against the limit too.
    ($this->ask)(4500000)->assertUnprocessable()->assertJsonValidationErrors('amount_minor');

    $this->asToken($this->worker)->getJson(($this->api)('advances'))->assertForbidden();
    ($this->step)($advance, 'approve', $this->worker)->assertForbidden();
    ($this->step)($advance, 'pay', $this->finance, ['method' => 'cash'])->assertStatus(409);
    $advance = ($this->step)($advance, 'approve', $this->boss)->assertOk()->json('data');
    expect($advance)->toMatchArray(['status' => 'submitted', 'steps_done' => 1]);
    ($this->step)($advance, 'approve', $this->boss)->assertForbidden();
    $advance = ($this->step)($advance, 'approve', $this->finance)->assertOk()->json('data');
    expect($advance['status'])->toBe('approved');
    ($this->step)($advance, 'pay', $this->finance, ['method' => 'payroll'])->assertUnprocessable()->assertJsonValidationErrors('method');
    $advance = ($this->step)($advance, 'pay', $this->finance, ['method' => 'bank'])->assertOk()->json('data');
    expect($advance)->toMatchArray(['status' => 'paid', 'outstanding_minor' => 1000000, 'settle_by' => '2026-11-27'])
        ->and($row('1160')['debit_minor'])->toBe(1000000)
        ->and($row('1120')['credit_minor'])->toBe(1000000)
        ->and(NotificationDelivery::query()->where('notification_key', 'expenses.advance_decided')->count())->toBe(2);

    // Taken back before anyone decided; not after.
    $other = ($this->ask)(100000)->json('data');
    $this->asToken($this->worker)->postJson(($this->api)("me/advances/{$other['id']}/withdraw"), ['base_version' => $other['version']])->assertOk()->assertJsonPath('data.status', 'withdrawn');
    $this->asToken($this->worker)->postJson(($this->api)("me/advances/{$advance['id']}/withdraw"), ['base_version' => $advance['version']])->assertStatus(409);
    expect(AuditLog::query()->where('action', 'expenses.advance_paid')->exists())->toBeTrue();
});

it('settles an advance with a smaller claim, then the rest handed back', function () {
    $row = ($this->books)();
    $advance = ($this->paidAdvance)(1000000);
    // A claim may name only one's own paid advance with something left.
    $this->asToken($this->worker)->postJson(($this->api)('me/claims'), ['title' => 'Trip', 'advance_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'lines' => [
        ['spent_on' => '2026-10-20', 'category_id' => $this->dailyKind['id'], 'description' => 'Site visit', 'per_diem_code' => 'OUTSIDE', 'days' => 1],
    ]])->assertUnprocessable()->assertJsonValidationErrors('advance_id');

    $claim = ($this->approvedClaim)(6, $advance['id']);
    $paid = $this->asToken($this->finance)->postJson(($this->api)("claims/{$claim['id']}/pay"), ['base_version' => $claim['version'], 'method' => 'cash'])->assertOk()->json('data');
    expect($paid)->toMatchArray(['status' => 'paid', 'pay_method' => 'advance', 'advance_minor' => 600000]);
    $advance = $this->asToken($this->finance)->getJson(($this->api)("advances/{$advance['id']}"))->assertOk()->json('data');
    expect($advance)->toMatchArray(['status' => 'paid', 'outstanding_minor' => 400000])->and($advance['can']['settle'])->toBeTrue()
        ->and(collect($advance['moves'])->pluck('kind')->all())->toBe(['paid', 'claim']);

    $this->asToken($this->worker)->postJson(($this->api)("advances/{$advance['id']}/settle"), ['base_version' => $advance['version'], 'method' => 'cash'])->assertForbidden();
    $done = $this->asToken($this->finance)->postJson(($this->api)("advances/{$advance['id']}/settle"), ['base_version' => $advance['version'], 'method' => 'cash'])->assertOk()->json('data');
    expect($done)->toMatchArray(['status' => 'settled', 'outstanding_minor' => 0])
        // Advances: paid 10,000 out, 6,000 by the claim, 4,000 handed back; cash: -10,000 + 4,000.
        ->and($row('1160'))->toBeNull()
        ->and($row('1110')['credit_minor'])->toBe(600000)
        ->and($row('2140'))->toBeNull();
});

it('pays only what is above the advance for a bigger claim', function () {
    $row = ($this->books)();
    $advance = ($this->paidAdvance)(200000);
    $claim = ($this->approvedClaim)(3, $advance['id']);
    $paid = $this->asToken($this->finance)->postJson(($this->api)("claims/{$claim['id']}/pay"), ['base_version' => $claim['version'], 'method' => 'bank'])->assertOk()->json('data');
    expect($paid)->toMatchArray(['status' => 'paid', 'pay_method' => 'bank', 'advance_minor' => 200000])
        ->and($row('1120')['credit_minor'])->toBe(100000)
        ->and($row('1160'))->toBeNull()
        ->and(Advance::query()->withoutGlobalScopes()->sole()->status)->toBe('settled');
});

it('recovers what is left from a month\'s salary through Payroll', function () {
    foreach ([$this->w->g1, $this->w->g2] as $group) {
        toggles()->enable($group, 'attendance', 'Test setup');
        toggles()->enable($group, 'payroll', 'Test setup');
    }
    $row = ($this->books)();
    $runner = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'payroll.view', 'payroll.run'], 'Payroll clerk')), $this->w->c1);
    $payApprover = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['payroll.view', 'payroll.approve'], 'Payroll approver')), $this->w->c1);
    $payroll = fn (string $path) => "/api/organizations/{$this->w->c1->id}/payroll/{$path}";
    $medical = $this->asToken($runner)->postJson($payroll('components'), ['code' => 'MEDICAL', 'name' => ['en' => 'Medical'], 'kind' => 'earning', 'taxable' => false])->json('data');
    $structure = $this->asToken($runner)->postJson($payroll('structures'), ['code' => 'STAFF', 'name' => ['en' => 'Staff'], 'items' => [['component_id' => $medical['id'], 'calc' => 'fixed', 'amount_minor' => 150000]]])->json('data');
    foreach ([$this->rahima, $this->mahmud] as $employee) {
        $this->asToken($runner)->postJson($payroll("employees/{$employee['id']}/salary"), ['structure_id' => $structure['id'], 'basic_minor' => 3000000, 'effective_from' => '2026-10-01'])->assertCreated();
    }

    $advance = ($this->paidAdvance)(500000);
    $this->asToken($this->finance)->postJson(($this->api)("advances/{$advance['id']}/settle"), ['base_version' => $advance['version'], 'method' => 'payroll', 'period' => '2026-09'])
        ->assertUnprocessable()->assertJsonValidationErrors('period');
    $this->asToken($this->finance)->postJson(($this->api)("advances/{$advance['id']}/settle"), ['base_version' => $advance['version'], 'method' => 'payroll', 'period' => '2026-10'])
        ->assertOk()->assertJsonPath('data.recover_period', '2026-10');

    $step = fn (array $run, string $name, string $token) => $this->asToken($token)->postJson($payroll("runs/{$run['id']}/{$name}"), ['base_version' => $run['version']]);
    $run = $this->asToken($runner)->postJson($payroll('runs'), ['period' => '2026-10'])->json('data');
    $run = $step($run, 'calculate', $runner)->assertOk()->json('data');
    $slips = collect($this->asToken($runner)->getJson($payroll("runs/{$run['id']}"))->json('data.slips'))->keyBy('employee_name');
    $lines = collect($this->asToken($runner)->getJson($payroll("runs/{$run['id']}/slips/{$slips['Rahima Akter']['id']}"))->json('data.lines'));
    expect($lines->firstWhere('code', 'ADVANCE'))->toMatchArray(['kind' => 'deduction', 'amount_minor' => 500000])
        ->and($slips['Rahima Akter']['net_minor'])->toBe($slips['Mahmud Hasan']['net_minor'] - 500000);
    $run = $step($run, 'submit', $runner)->assertOk()->json('data');
    $step($run, 'approve', $payApprover)->assertOk()->assertJsonPath('data.status', 'approved');

    $settled = $this->asToken($this->finance)->getJson(($this->api)("advances/{$advance['id']}"))->json('data');
    expect($settled)->toMatchArray(['status' => 'settled', 'outstanding_minor' => 0])
        ->and(collect($settled['moves'])->pluck('kind')->all())->toBe(['paid', 'salary'])
        // The salary took the advance back: nothing owed on it any more.
        ->and($row('1160'))->toBeNull();
});

it('keeps advances to the employee, the company and the module, and flags those past their day', function () {
    $advance = ($this->paidAdvance)(300000);
    $this->asToken($this->boss)->getJson(($this->api)("me/advances/{$advance['id']}"))->assertNotFound();
    expect($this->asToken($this->worker)->getJson(($this->api)("me/advances/{$advance['id']}"))->assertOk()->json('data.status'))->toBe('paid');
    $c2 = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['expenses.pay', 'expenses.manage'], 'C2 finance')), $this->w->c2);
    $this->asToken($c2)->getJson(($this->api)("advances/{$advance['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($c2)->postJson(($this->api)("advances/{$advance['id']}/settle", $this->w->c2), ['base_version' => $advance['version'], 'method' => 'cash'])->assertNotFound();

    $this->travelTo(CarbonImmutable::parse('2026-11-30 04:00:00', 'UTC'));
    $finance = orgToken($this->financeUser, $this->w->c1);
    $this->asToken($finance)->getJson('/api/attention')->assertOk()->assertJsonFragment(['key' => 'expenses.advances_overdue', 'count' => 1]);
    expect($this->asToken($finance)->getJson(($this->api)('advances?status=overdue'))->assertOk()->json('data.0.overdue'))->toBeTrue();

    toggles()->disable($this->w->g1, 'expenses', 'Not used');
    $this->asToken($finance)->getJson(($this->api)('advances'))->assertForbidden();
});
