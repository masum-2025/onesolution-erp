<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Notifications\Models\NotificationDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\Expenses\Models\Claim;

/*
 * EXP-1: kinds of expense (the usual ones from the rule), an employee's
 * claim with lines, mileage and receipts, the limits from rules (receipt
 * needed, how old, a second approval above an amount), approval steps (the
 * manager first, never one's own, the finance people second), the books
 * (the expense when approved, the payment when paid) and paying back with
 * a month's salary through Payroll. Company C1; today is Wednesday
 * 28 October 2026.
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

    $this->adminUser = staffWithRoles($c1, makeRole($c1, ['hrm.view', 'expenses.manage'], 'Expenses admin'));
    $this->admin = orgToken($this->adminUser, $c1);
    $this->bossUser = staffWithRoles($c1, makeRole($c1, ['expenses.approve', 'expenses.claim'], 'Manager'));
    $this->boss = orgToken($this->bossUser, $c1);
    $this->otherApprover = orgToken(staffWithRoles($c1, makeRole($c1, ['expenses.approve'], 'Approver')), $c1);
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
    $this->asToken($this->admin)->postJson(($this->api)('categories/defaults'))->assertOk()->assertJsonPath('data.added', 5);
    $this->kinds = collect($this->asToken($this->admin)->getJson(($this->api)('categories'))->assertOk()->json('data'))->keyBy('code');

    $this->line = fn (string $kind, int $amount, string $on = '2026-10-20', array $data = []) => [
        'spent_on' => $on, 'category_id' => $this->kinds[$kind]['id'], 'description' => "{$kind} on {$on}", 'amount_minor' => $amount, ...$data,
    ];
    $this->draft = fn (array $lines, ?string $token = null, array $data = []) => $this->asToken($token ?? $this->worker)
        ->postJson(($this->api)('me/claims'), ['title' => 'Client visit to Chattogram', 'lines' => $lines, ...$data]);
    $this->mine = fn (array $claim, string $step, ?string $token = null) => $this->asToken($token ?? $this->worker)
        ->postJson(($this->api)("me/claims/{$claim['id']}/{$step}"), ['base_version' => $claim['version']]);
    $this->receipt = fn (array $claim, ?string $lineId, ?UploadedFile $file = null, ?string $token = null) => $this->asToken($token ?? $this->worker)
        ->post(($this->api)("me/claims/{$claim['id']}/receipts"), ['file' => $file ?? UploadedFile::fake()->create('taxi.pdf', 80, 'application/pdf'), 'line_id' => $lineId], ['Accept' => 'application/json']);
    $this->decide = fn (array $claim, string $step, string $token, array $data = []) => $this->asToken($token)
        ->postJson(($this->api)("claims/{$claim['id']}/{$step}"), ['base_version' => $claim['version'], ...$data]);
    $this->pay = fn (array $claim, array $data, ?string $token = null) => $this->asToken($token ?? $this->finance)
        ->postJson(($this->api)("claims/{$claim['id']}/pay"), ['base_version' => $claim['version'], ...$data]);
    // A small claim sent for approval (no receipt needed below the limit).
    $this->sent = function (int $amount = 30000, ?string $token = null) {
        $claim = ($this->draft)([($this->line)('MEALS', $amount)], $token)->assertCreated()->json('data');

        return ($this->mine)($claim, 'submit', $token)->assertOk()->json('data');
    };
});

it('starts with the usual kinds from the rule, kept by those who manage expenses', function () {
    expect($this->kinds->keys()->all())->toEqualCanonicalizing(['TRAVEL', 'MEALS', 'OFFICE', 'PHONE', 'OTHER'])
        ->and($this->kinds['TRAVEL']['posting_key'])->toBe('expenses.travel');
    $this->asToken($this->admin)->postJson(($this->api)('categories/defaults'))->assertOk()->assertJsonPath('data.added', 0);

    $this->asToken($this->worker)->postJson(($this->api)('categories'), ['code' => 'CAR', 'name' => ['en' => 'Own car'], 'posting_key' => 'expenses.travel'])->assertForbidden();
    $this->asToken($this->admin)->postJson(($this->api)('categories'), ['code' => 'CAR', 'name' => ['en' => 'Own car'], 'posting_key' => '5600'])
        ->assertUnprocessable()->assertJsonValidationErrors('posting_key');
    $this->asToken($this->admin)->postJson(($this->api)('categories'), ['code' => 'CAR', 'name' => ['en' => 'Own car'], 'posting_key' => 'expenses.travel', 'secret' => 1])
        ->assertUnprocessable();
    $car = $this->asToken($this->admin)->postJson(($this->api)('categories'), ['code' => 'CAR', 'name' => ['en' => 'Own car', 'bn' => 'নিজের গাড়ি'], 'posting_key' => 'expenses.travel', 'mileage' => true])
        ->assertCreated()->json('data');
    expect($car)->toMatchArray(['code' => 'CAR', 'mileage' => true, 'is_active' => true]);

    $phone = $this->kinds['PHONE'];
    $this->asToken($this->admin)->patchJson(($this->api)("categories/{$phone['id']}"), ['base_version' => $phone['version'], 'code' => 'PHONE', 'name' => $phone['names'], 'posting_key' => 'expenses.communication', 'is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false);
    ($this->draft)([($this->line)('PHONE', 10000)])->assertUnprocessable()->assertJsonPath('code', 'category_closed');
    expect(collect($this->asToken($this->worker)->getJson(($this->api)('me'))->assertOk()->json('data.categories'))->pluck('code'))->not->toContain('PHONE')
        ->and(AuditLog::query()->where('action', 'expenses.category_changed')->count())->toBe(1);
});

it('writes a draft within the rules, mileage at the rule rate and receipts kept private', function () {
    ($this->draft)([($this->line)('MEALS', 1000, '2026-10-29')])->assertUnprocessable()->assertJsonValidationErrors('lines.0.spent_on');
    ($this->draft)([($this->line)('MEALS', 1000, '2026-08-01')])->assertUnprocessable()->assertJsonValidationErrors('lines.0.spent_on');
    ($this->draft)([($this->line)('MEALS', 0)])->assertUnprocessable()->assertJsonValidationErrors('lines.0.amount_minor');
    ($this->draft)([($this->line)('MEALS', 1000)], data: ['organization_id' => $this->w->c2->id])->assertUnprocessable();

    $car = $this->asToken($this->admin)->postJson(($this->api)('categories'), ['code' => 'CAR', 'name' => ['en' => 'Own car'], 'posting_key' => 'expenses.travel', 'mileage' => true])->json('data');
    $this->kinds['CAR'] = $car;
    ($this->draft)([($this->line)('CAR', 0, data: ['distance_tenths' => 125])])->assertUnprocessable()->assertJsonValidationErrors('lines.0.distance_tenths');
    trustedOrgRule($this->w->c1, 'expenses.mileage_rate', 1500);

    $claim = ($this->draft)([($this->line)('TRAVEL', 80000), ($this->line)('CAR', 0, '2026-10-21', ['distance_tenths' => 125])], data: ['op_id' => 'op-1'])
        ->assertCreated()->json('data');
    // 12.5 km at 15.00 a kilometre.
    expect($claim)->toMatchArray(['status' => 'draft', 'number' => null, 'total_minor' => 80000 + 18750, 'currency' => 'BDT'])
        ->and($claim['can'])->toMatchArray(['edit' => true, 'submit' => true, 'withdraw' => false]);
    // The same write sent again (offline, a retry) is the same claim.
    expect(($this->draft)([($this->line)('MEALS', 500)], data: ['op_id' => 'op-1'])->json('data.id'))->toBe($claim['id'])
        ->and(Claim::query()->withoutGlobalScopes()->count())->toBe(1);

    // Above 500.00 a line needs its receipt.
    ($this->mine)($claim, 'submit')->assertUnprocessable()->assertJsonPath('code', 'receipt_needed');
    ($this->receipt)($claim, $claim['lines'][0]['id'], UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'))->assertUnprocessable()->assertJsonValidationErrors('file');
    ($this->receipt)($claim, $claim['lines'][0]['id'], UploadedFile::fake()->create('huge.pdf', 6000, 'application/pdf'))->assertUnprocessable()->assertJsonValidationErrors('file');
    ($this->receipt)($claim, '01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertUnprocessable()->assertJsonValidationErrors('line_id');
    $withReceipt = ($this->receipt)($claim, $claim['lines'][0]['id'])->assertCreated()->json('data');
    expect($withReceipt['receipts'])->toHaveCount(1)
        ->and(Storage::disk('local')->allFiles("expenses/{$this->w->c1->id}/{$claim['id']}"))->toHaveCount(1);

    $url = $withReceipt['receipts'][0]['url'];
    expect($url)->toStartWith("/files/expenses/{$this->w->c1->id}/");
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();

    // Changing a line keeps it (and its receipt); a stale version is refused.
    $lines = collect($withReceipt['lines'])->map(fn (array $line) => collect($line)->only(['id', 'spent_on', 'category_id', 'description', 'amount_minor', 'distance_tenths'])->all())->all();
    $lines[0]['amount_minor'] = 90000;
    $updated = $this->asToken($this->worker)->patchJson(($this->api)("me/claims/{$claim['id']}"), ['base_version' => $withReceipt['version'], 'lines' => $lines])->assertOk()->json('data');
    expect($updated['total_minor'])->toBe(90000 + 18750)->and($updated['receipts'][0]['line_id'])->toBe($claim['lines'][0]['id']);
    $this->asToken($this->worker)->patchJson(($this->api)("me/claims/{$claim['id']}"), ['base_version' => $withReceipt['version'], 'title' => 'Again'])
        ->assertStatus(409)->assertJsonPath('code', 'version_conflict');

    $sent = ($this->mine)($updated, 'submit')->assertOk()->json('data');
    expect($sent)->toMatchArray(['status' => 'submitted', 'number' => 'EXP-2026-00001', 'steps_needed' => 1])
        ->and($sent['can']['edit'])->toBeFalse();
    ($this->receipt)($sent, null)->assertStatus(409)->assertJsonPath('code', 'not_draft');
    $this->asToken($this->worker)->deleteJson(($this->api)("me/claims/{$sent['id']}"), ['base_version' => $sent['version']])->assertStatus(409);
    // Only the manager hears about it.
    expect(NotificationDelivery::query()->where('notification_key', 'expenses.submitted')->pluck('user_id')->all())->toBe([$this->bossUser->id]);
});

it('is decided by the manager first, never by the one who claims, and refused with a reason', function () {
    $claim = ($this->sent)();
    $this->asToken($this->worker)->getJson(($this->api)('claims'))->assertForbidden();
    ($this->decide)($claim, 'approve', $this->otherApprover)->assertForbidden()->assertJsonPath('code', 'cannot_decide');
    ($this->decide)($claim, 'approve', $this->finance)->assertForbidden();

    $waiting = $this->asToken($this->boss)->getJson(($this->api)('claims?waiting=1'))->assertOk()->json('data');
    expect($waiting)->toHaveCount(1)->and($waiting[0]['employee']['name'])->toBe('Rahima Akter');
    expect($this->asToken($this->otherApprover)->getJson(($this->api)('claims?waiting=1'))->json('data'))->toBe([]);
    $this->asToken($this->boss)->getJson('/api/attention')->assertOk()->assertJsonFragment(['key' => 'expenses.claims_waiting', 'count' => 1]);
    expect(collect($this->asToken($this->otherApprover)->getJson('/api/attention')->json('data'))->pluck('key'))->not->toContain('expenses.claims_waiting');

    ($this->decide)($claim, 'reject', $this->boss)->assertUnprocessable()->assertJsonValidationErrors('note');
    $rejected = ($this->decide)($claim, 'reject', $this->boss, ['note' => 'Lunch is paid by the client'])->assertOk()->json('data');
    expect($rejected)->toMatchArray(['status' => 'rejected', 'decision_note' => 'Lunch is paid by the client'])
        ->and($rejected['approvals'][0])->toMatchArray(['step' => 1, 'decision' => 'rejected'])
        ->and(NotificationDelivery::query()->where('notification_key', 'expenses.decided')->sole()->user_id)->toBe($this->workerUser->id);
    ($this->decide)($rejected, 'approve', $this->boss)->assertForbidden();

    // The manager's own claim: not theirs to decide; someone who manages expenses does it.
    $own = ($this->sent)(20000, $this->boss);
    ($this->decide)($own, 'approve', $this->boss)->assertForbidden()->assertJsonPath('code', 'cannot_decide');
    ($this->decide)($own, 'approve', $this->admin)->assertOk()->assertJsonPath('data.status', 'approved');

    // Taken back before anyone decided; not after.
    $again = ($this->sent)();
    $back = ($this->mine)($again, 'withdraw')->assertOk()->json('data');
    expect($back['status'])->toBe('withdrawn');
    ($this->decide)($back, 'approve', $this->boss)->assertForbidden();
    expect(AuditLog::query()->whereIn('action', ['expenses.submitted', 'expenses.rejected', 'expenses.approved', 'expenses.withdrawn'])->count())->toBe(6);
});

it('needs the finance people as well above the rule amount, books the expense and pays it back', function () {
    toggles()->enable($this->w->g1, 'accounting', 'Test setup');
    $books = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['accounting.view', 'accounting.manage'], 'Books')), $this->w->c1);
    setUpBooks($this, $books, $this->w->c1)->assertCreated();
    $row = fn (string $code) => collect($this->asToken($books)->getJson("/api/organizations/{$this->w->c1->id}/accounting/reports/trial-balance?as_of=2026-10-28")->json('data.rows'))->firstWhere('code', $code);

    $claim = ($this->draft)([($this->line)('OFFICE', 600000), ($this->line)('TRAVEL', 40000)])->assertCreated()->json('data');
    ($this->receipt)($claim, $claim['lines'][0]['id'])->assertCreated();
    $claim = ($this->mine)($this->asToken($this->worker)->getJson(($this->api)("me/claims/{$claim['id']}"))->json('data'), 'submit')->assertOk()->json('data');
    expect($claim['steps_needed'])->toBe(2);

    ($this->pay)($claim, ['method' => 'cash'])->assertStatus(409)->assertJsonPath('code', 'not_payable');
    $claim = ($this->decide)($claim, 'approve', $this->boss)->assertOk()->json('data');
    expect($claim)->toMatchArray(['status' => 'submitted', 'steps_done' => 1, 'posted' => false]);
    ($this->decide)($claim, 'approve', $this->boss)->assertForbidden();
    ($this->decide)($claim, 'approve', $this->otherApprover)->assertForbidden();
    expect(NotificationDelivery::query()->where('notification_key', 'expenses.submitted')->pluck('user_id')->all())->toContain($this->financeUser->id);

    $claim = ($this->decide)($claim, 'approve', $this->finance)->assertOk()->json('data');
    expect($claim)->toMatchArray(['status' => 'approved', 'steps_done' => 2, 'posted' => true])
        ->and($row('5500')['debit_minor'])->toBe(600000)
        ->and($row('5600')['debit_minor'])->toBe(40000)
        ->and($row('2140')['credit_minor'])->toBe(640000);

    $this->asToken($this->boss)->getJson(($this->api)("claims/{$claim['id']}"))->assertOk()->assertJsonPath('data.can.pay', false);
    ($this->pay)($claim, ['method' => 'cash'], $this->boss)->assertForbidden();
    ($this->pay)($claim, ['method' => 'cheque'])->assertUnprocessable()->assertJsonValidationErrors('method');
    $paid = ($this->pay)($claim, ['method' => 'bank'])->assertOk()->json('data');
    expect($paid)->toMatchArray(['status' => 'paid', 'pay_method' => 'bank'])
        ->and($row('2140'))->toBeNull()
        ->and($row('1120')['credit_minor'])->toBe(640000)
        ->and(NotificationDelivery::query()->where('notification_key', 'expenses.paid')->sole()->user_id)->toBe($this->workerUser->id)
        ->and(AuditLog::query()->where('action', 'expenses.paid')->count())->toBe(1);
    ($this->pay)($paid, ['method' => 'cash'])->assertStatus(409);

    $spent = $this->asToken($this->finance)->getJson('/api/modules/expenses/dashboard/spent')->assertOk()->json('data');
    expect($spent['value'])->toBe(640000);
    $this->asToken($this->worker)->getJson('/api/modules/expenses/dashboard/spent')->assertForbidden();
});

it('is paid back with a month\'s salary: on the slip, not a salary cost, and paid when the month is approved', function () {
    $claim = ($this->decide)(($this->sent)(45000), 'approve', $this->boss)->assertOk()->json('data');
    ($this->pay)($claim, ['method' => 'payroll', 'period' => '2026-10'])->assertUnprocessable()->assertJsonPath('code', 'payroll_off');

    foreach ([$this->w->g1, $this->w->g2] as $group) {
        toggles()->enable($group, 'attendance', 'Test setup');
        toggles()->enable($group, 'payroll', 'Test setup');
        toggles()->enable($group, 'accounting', 'Test setup');
    }
    $books = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['accounting.view', 'accounting.manage'], 'Books')), $this->w->c1);
    setUpBooks($this, $books, $this->w->c1)->assertCreated();
    $runner = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'payroll.view', 'payroll.run'], 'Payroll clerk')), $this->w->c1);
    $payApprover = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['payroll.view', 'payroll.approve'], 'Payroll approver')), $this->w->c1);
    $payroll = fn (string $path) => "/api/organizations/{$this->w->c1->id}/payroll/{$path}";
    $medical = $this->asToken($runner)->postJson($payroll('components'), ['code' => 'MEDICAL', 'name' => ['en' => 'Medical'], 'kind' => 'earning', 'taxable' => false])->assertCreated()->json('data');
    $structure = $this->asToken($runner)->postJson($payroll('structures'), ['code' => 'STAFF', 'name' => ['en' => 'Staff'], 'items' => [
        ['component_id' => $medical['id'], 'calc' => 'fixed', 'amount_minor' => 150000],
    ]])->assertCreated()->json('data');
    foreach ([$this->rahima, $this->mahmud] as $employee) {
        $this->asToken($runner)->postJson($payroll("employees/{$employee['id']}/salary"), ['structure_id' => $structure['id'], 'basic_minor' => 3000000, 'effective_from' => '2026-10-01'])->assertCreated();
    }

    ($this->pay)($claim, ['method' => 'payroll'])->assertUnprocessable()->assertJsonValidationErrors('period');
    ($this->pay)($claim, ['method' => 'payroll', 'period' => '2026-09'])->assertUnprocessable()->assertJsonValidationErrors('period');
    $claim = ($this->pay)($claim, ['method' => 'payroll', 'period' => '2026-10'])->assertOk()->json('data');
    expect($claim)->toMatchArray(['status' => 'approved', 'pay_method' => 'payroll', 'pay_period' => '2026-10']);
    $this->asToken($this->admin)->getJson(($this->api)('claims?period=2026-10'))->assertOk()->assertJsonCount(1, 'data');

    $step = fn (array $run, string $name, string $token) => $this->asToken($token)->postJson($payroll("runs/{$run['id']}/{$name}"), ['base_version' => $run['version']]);
    $run = $this->asToken($runner)->postJson($payroll('runs'), ['period' => '2026-10'])->assertCreated()->json('data');
    $run = $step($run, 'calculate', $runner)->assertOk()->json('data');
    $slips = collect($this->asToken($runner)->getJson($payroll("runs/{$run['id']}"))->json('data.slips'))->keyBy('employee_name');
    $lines = collect($this->asToken($runner)->getJson($payroll("runs/{$run['id']}/slips/{$slips['Rahima Akter']['id']}"))->json('data.lines'));
    expect($lines->firstWhere('code', 'EXPENSES')['amount_minor'])->toBe(45000);
    $mahmudLines = collect($this->asToken($runner)->getJson($payroll("runs/{$run['id']}/slips/{$slips['Mahmud Hasan']['id']}"))->json('data.lines'));
    expect($mahmudLines->pluck('code'))->not->toContain('EXPENSES')
        ->and($slips['Rahima Akter']['earnings_minor'])->toBe($slips['Mahmud Hasan']['earnings_minor'] + 45000);

    $run = $step($run, 'submit', $runner)->assertOk()->json('data');
    $step($run, 'approve', $payApprover)->assertOk()->assertJsonPath('data.status', 'approved');

    $paid = $this->asToken($this->worker)->getJson(($this->api)("me/claims/{$claim['id']}"))->assertOk()->json('data');
    expect($paid)->toMatchArray(['status' => 'paid', 'pay_method' => 'payroll'])
        ->and(NotificationDelivery::query()->where('notification_key', 'expenses.paid')->sole()->user_id)->toBe($this->workerUser->id);

    // The salary cost is both people's pay without the claim; the claim was booked when approved (before the books existed here).
    $row = fn (string $code) => collect($this->asToken($books)->getJson("/api/organizations/{$this->w->c1->id}/accounting/reports/trial-balance?as_of=2026-10-31")->json('data.rows'))->firstWhere('code', $code);
    expect($row('5200')['debit_minor'])->toBe($slips['Mahmud Hasan']['earnings_minor'] * 2);
});

it('keeps claims to the company, to the employee and to the module being on', function () {
    $claim = ($this->sent)();
    $other = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['expenses.claim'], 'Not linked'));
    $this->asToken(orgToken($other, $this->w->c1))->getJson(($this->api)('me'))->assertUnprocessable()->assertJsonPath('code', 'not_linked');
    $this->asToken($this->boss)->getJson(($this->api)("me/claims/{$claim['id']}"))->assertNotFound();

    $c2 = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['expenses.approve', 'expenses.pay', 'expenses.manage'], 'C2 expenses')), $this->w->c2);
    $this->asToken($c2)->getJson(($this->api)("claims/{$claim['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($c2)->postJson(($this->api)("claims/{$claim['id']}/approve", $this->w->c2), ['base_version' => $claim['version']])->assertNotFound();
    $this->asToken($c2)->getJson(($this->api)('claims', $this->w->c2))->assertOk()->assertJsonPath('data', []);
    $this->asToken($c2)->getJson(($this->api)('categories', $this->w->c2))->assertOk()->assertJsonPath('data', []);
    $this->asToken($c2)->getJson(($this->api)('claims', $this->w->c1))->assertNotFound();

    // Drafts are their writers' own.
    $draft = ($this->draft)([($this->line)('MEALS', 1000)])->json('data');
    $this->asToken($this->admin)->getJson(($this->api)("claims/{$draft['id']}"))->assertNotFound();
    expect(collect($this->asToken($this->admin)->getJson(($this->api)('claims'))->json('data'))->pluck('id')->all())->toBe([$claim['id']]);

    // A receipt link of one company opens nothing with another company's id in it.
    $draft = ($this->receipt)($draft, null)->assertCreated()->json('data');
    $receiptId = $draft['receipts'][0]['id'];
    $forged = URL::temporarySignedRoute('expenses.receipts.show', now()->addMinutes(5), ['organization' => $this->w->c2->id, 'receipt' => $receiptId], absolute: false);
    $this->get($forged)->assertNotFound();

    toggles()->disable($this->w->g1, 'expenses', 'Not used');
    $this->asToken($this->worker)->getJson(($this->api)('me'))->assertForbidden();
    $this->asToken($this->boss)->postJson(($this->api)("claims/{$claim['id']}/approve"), ['base_version' => $claim['version']])->assertForbidden();
    expect(Claim::query()->withoutGlobalScopes()->count())->toBe(2);
});
