<?php

use App\Platform\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Modules\CourseRegistration\Events\RegistrationApproved;
use Modules\CourseRegistration\Models\Registration;
use Modules\EducationFees\Events\FeePaid;
use Modules\EducationFees\Models\Allocation;
use Modules\EducationFees\Services\Billing;

/*
 * FEE-2a: collecting fees. Receipts meet the oldest bills first (or the
 * bills named), more than owed waits as an advance used by the next bill,
 * part payments and methods by rule, voids and refunds decided by someone
 * else, cancelling a paid bill, per-credit fees, takings, isolation.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-05 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'education', 'Test setup');
        toggles()->enable($group, 'education_fees', 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->checker = createMember($this->w->c1);
    $this->checkerToken = orgToken($this->checker, $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/education/{$path}";
    $this->fees = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/fees/{$path}";
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);

    $this->school = eduSchool($this);
    $this->sectionB = ($this->as)()->postJson(($this->api)('structure/sections'), ['unit_id' => $this->w->b1->id, 'session_id' => $this->school->session['id'], 'level_id' => $this->school->class6['id'], 'name' => 'B', 'capacity' => 40])->assertCreated()->json('data');
    $this->rahim = newStudent($this, $this->school, [
        'name' => 'Rahim', 'guardians' => [['name' => 'Abdul', 'phone' => '01711-000001', 'relation' => 'father']],
        'enrollment' => ['session_id' => $this->school->session['id'], 'level_id' => $this->school->class6['id'], 'section_id' => $this->sectionB['id']],
    ])->assertCreated()->json('data');
    $this->tuition = ($this->as)()->postJson(($this->fees)('heads'), ['code' => 'TUI', 'name' => ['en' => 'Tuition'], 'frequency' => 'monthly', 'income_key' => 'education_fees.tuition_income'])->json('data');
    $structure = ($this->as)()->postJson(($this->fees)('structures'), ['name' => 'Fees', 'session_id' => $this->school->session['id'], 'lines' => [['head_id' => $this->tuition['id'], 'amount_minor' => 150000]]])->json('data');
    ($this->as)()->postJson(($this->fees)("structures/{$structure['id']}/activate"), ['base_version' => $structure['version']])->assertOk();
    $this->month = function (string $period) {
        $run = ($this->as)()->postJson(($this->fees)('runs'), ['kind' => 'monthly', 'session_id' => $this->school->session['id'], 'period' => $period, 'issue_date' => "{$period}-01"])->assertCreated()->json('data');
        ($this->as)()->postJson(($this->fees)("runs/{$run['id']}/finalize"), ['base_version' => $run['version']])->assertOk();

        return ($this->as)()->getJson(($this->fees)("bills?run_id={$run['id']}"))->json('data.0');
    };
    $this->pay = fn (int $amount, array $extra = [], ?string $token = null) => ($this->as)($token)->postJson(($this->fees)('receipts'), [
        'student_id' => $this->rahim['id'], 'amount_minor' => $amount, 'method' => 'cash', 'received_on' => '2026-01-05', ...$extra,
    ]);
    $this->statement = fn () => ($this->as)()->getJson(($this->fees)("students/{$this->rahim['id']}"))->assertOk()->json('data');
});

it('meets the oldest bill first, keeps what is left as an advance and uses it on the next bill', function () {
    Event::fake([FeePaid::class]);
    $january = ($this->month)('2026-01');
    $february = ($this->month)('2026-02');

    // 2 000.00 meets January (1 500.00) and 500.00 of February.
    $receipt = ($this->pay)(200000, ['op_id' => 'counter-1'])->assertCreated()->json('data');
    expect($receipt)->toMatchArray(['number' => 'RCT-2026-000001', 'amount_minor' => 200000, 'status' => 'valid', 'advance_minor' => 0])
        ->and($receipt['bills'])->toBe([
            ['bill_id' => $january['id'], 'number' => $january['number'], 'amount_minor' => 150000],
            ['bill_id' => $february['id'], 'number' => $february['number'], 'amount_minor' => 50000],
        ]);
    // The same tap twice is one receipt.
    ($this->pay)(200000, ['op_id' => 'counter-1'])->assertCreated()->assertJsonPath('data.id', $receipt['id']);
    Event::assertDispatchedTimes(FeePaid::class, 1);

    // 1 500.00 more: February's last 1 000.00, and 500.00 waits as an advance…
    ($this->pay)(150000)->assertCreated()->assertJsonPath('data.advance_minor', 50000);
    $statement = ($this->statement)();
    expect($statement)->toMatchArray(['owed_minor' => 0, 'advance_minor' => 50000])
        ->and(collect($statement['bills'])->pluck('status')->unique()->all())->toBe(['paid']);
    // …which meets March as soon as it is issued.
    $march = ($this->month)('2026-03');
    expect($march)->toMatchArray(['paid_minor' => 50000, 'balance_minor' => 100000, 'status' => 'open'])
        ->and(($this->statement)()['advance_minor'])->toBe(0);
});

it('takes the bills and amounts the office names, and keeps to the rules on methods and part payments', function () {
    $january = ($this->month)('2026-01');
    $february = ($this->month)('2026-02');

    $paid = ($this->pay)(150000, ['allocations' => [['bill_id' => $february['id'], 'amount_minor' => 150000]]])->assertCreated()->json('data');
    expect($paid['bills'][0]['bill_id'])->toBe($february['id']);
    ($this->pay)(10000, ['allocations' => [['bill_id' => $january['id'], 'amount_minor' => 200000]]])->assertUnprocessable()->assertJsonPath('code', 'more_than_owed');
    ($this->pay)(10000, ['allocations' => [['bill_id' => $january['id'], 'amount_minor' => 20000]]])->assertUnprocessable()->assertJsonPath('code', 'allocations_exceed_amount');
    ($this->pay)(10000, ['method' => 'bank'])->assertUnprocessable()->assertJsonValidationErrors(['reference']);
    ($this->pay)(10000, ['received_on' => '2026-02-01'])->assertUnprocessable()->assertJsonValidationErrors(['received_on']);

    orgRule($this->w->c1, 'education_fees.payment_methods', ['cash']);
    ($this->pay)(10000, ['method' => 'mobile', 'reference' => 'BK123'])->assertUnprocessable()->assertJsonPath('code', 'method_not_allowed');
    orgRule($this->w->c1, 'education_fees.allow_partial_payment', false);
    ($this->pay)(10000)->assertUnprocessable()->assertJsonPath('code', 'partial_not_allowed');
    ($this->pay)(150000)->assertCreated();
});

it('voids a receipt only with someone else, and what it paid is owed again', function () {
    $january = ($this->month)('2026-01');
    $receipt = ($this->pay)(180000)->assertCreated()->json('data');
    expect($receipt['advance_minor'])->toBe(30000);

    $void = ($this->as)()->postJson(($this->fees)("receipts/{$receipt['id']}/void"), ['reason' => 'Wrong student'])->assertCreated()->json('data');
    expect($void['status'])->toBe('pending');
    ($this->as)()->postJson(($this->fees)("receipts/{$receipt['id']}/void"), ['reason' => 'Again'])->assertStatus(409)->assertJsonPath('code', 'void_pending');
    // Neither who took the money nor who asked approves it.
    ($this->as)()->postJson(($this->fees)("voids/{$void['id']}/approve"), ['base_version' => $void['version']])->assertForbidden()->assertJsonPath('code', 'own_approval');
    ($this->as)($this->checkerToken)->postJson(($this->fees)("voids/{$void['id']}/approve"), ['base_version' => $void['version']])->assertOk()->assertJsonPath('data.status', 'approved');

    expect(($this->as)()->getJson(($this->fees)("receipts/{$receipt['id']}"))->json('data'))->toMatchArray(['status' => 'voided', 'bills' => [], 'advance_minor' => 0]);
    $bill = ($this->as)()->getJson(($this->fees)("bills/{$january['id']}"))->json('data');
    expect($bill)->toMatchArray(['status' => 'open', 'paid_minor' => 0, 'balance_minor' => 150000])
        ->and(($this->statement)()['advance_minor'])->toBe(0);
    // Money rows are never changed: the undoing rows point at what they undo.
    expect(Allocation::query()->withoutGlobalScopes()->where('kind', 'reversal')->sum('amount_minor'))->toEqual(-150000);
    expect(AuditLog::query()->where('action', 'education_fees.receipt_voided')->count())->toBe(1);
});

it('refuses to void a receipt whose advance a later bill already used', function () {
    ($this->month)('2026-01');
    $receipt = ($this->pay)(200000)->json('data');
    ($this->month)('2026-02');
    $void = ($this->as)()->postJson(($this->fees)("receipts/{$receipt['id']}/void"), ['reason' => 'Mistake'])->json('data');
    ($this->as)($this->checkerToken)->postJson(($this->fees)("voids/{$void['id']}/approve"), ['base_version' => $void['version']])->assertStatus(409)->assertJsonPath('code', 'advance_used');

    // Without the second-person rule a void is done at once.
    trustedOrgRule($this->w->c1, 'education_fees.void_needs_second_person', false);
    $small = ($this->pay)(1000)->json('data');
    ($this->as)()->postJson(($this->fees)("receipts/{$small['id']}/void"), ['reason' => 'Test'])->assertCreated()->assertJsonPath('data.status', 'approved');
});

it('moves what was paid on a cancelled bill to the advance, and pays an advance back with approval', function () {
    $january = ($this->month)('2026-01');
    ($this->pay)(100000)->assertCreated();
    $bill = ($this->as)()->getJson(($this->fees)("bills/{$january['id']}"))->json('data');
    ($this->as)()->postJson(($this->fees)("bills/{$bill['id']}/cancel"), ['reason' => 'Left the school', 'base_version' => $bill['version']])->assertOk()
        ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.paid_minor', 0);
    expect(($this->statement)()['advance_minor'])->toBe(100000);

    ($this->as)()->postJson(($this->fees)('refunds'), ['student_id' => $this->rahim['id'], 'amount_minor' => 200000, 'method' => 'cash', 'reason' => 'Leaving'])
        ->assertUnprocessable()->assertJsonPath('code', 'advance_too_small');
    $refund = ($this->as)()->postJson(($this->fees)('refunds'), ['student_id' => $this->rahim['id'], 'amount_minor' => 100000, 'method' => 'cash', 'reason' => 'Leaving'])->assertCreated()->json('data');
    expect($refund['status'])->toBe('pending');
    ($this->as)()->postJson(($this->fees)("refunds/{$refund['id']}/approve"), ['base_version' => $refund['version']])->assertForbidden();
    ($this->as)($this->checkerToken)->postJson(($this->fees)("refunds/{$refund['id']}/approve"), ['base_version' => $refund['version']])->assertOk()->assertJsonPath('data.status', 'approved');
    expect(($this->statement)()['advance_minor'])->toBe(0);
});

it('bills per-credit fees when a registration is approved, and credits fewer credits back', function () {
    toggles()->enable($this->w->g1, 'course_registration', 'Test setup');
    $credit = ($this->as)()->postJson(($this->fees)('heads'), ['code' => 'CRD', 'name' => ['en' => 'Credit fee'], 'frequency' => 'per_credit', 'income_key' => 'education_fees.tuition_income'])->json('data');
    $rates = ($this->as)()->postJson(($this->fees)('structures'), ['name' => 'Credits', 'session_id' => $this->school->session['id'], 'level_id' => $this->school->class6['id'], 'lines' => [['head_id' => $credit['id'], 'amount_minor' => 250000]]])->json('data');
    ($this->as)()->postJson(($this->fees)("structures/{$rates['id']}/activate"), ['base_version' => $rates['version']])->assertOk();

    // An approved registration of 15 credits: 15 × 2 500.00.
    $registration = new Registration;
    $registration->forceFill(['organization_id' => $this->w->c1->id, 'unit_id' => $this->w->b1->id, 'session_id' => $this->school->session['id'], 'student_id' => $this->rahim['id'], 'status' => 'approved', 'credits_centi' => 1500, 'version' => 1])->save();
    event(new RegistrationApproved($this->w->c1->id, $registration->id, $this->rahim['id']));
    $bills = collect(($this->statement)()['bills']);
    expect($bills)->toHaveCount(1)->and($bills[0])->toMatchArray(['source' => 'credits', 'total_minor' => 3750000, 'status' => 'open']);

    // Two more credits bill the difference; then down to 12 credits: 5 × 2 500.00 waits as an advance.
    $billing = app(Billing::class);
    $billing->billCredits($this->w->c1->fresh(), $this->rahim['id'], $this->school->session['id'], 1700);
    expect(collect(($this->statement)()['bills'])->pluck('total_minor')->sort()->values()->all())->toBe([500000, 3750000]);
    expect($billing->billCredits($this->w->c1->fresh(), $this->rahim['id'], $this->school->session['id'], 1200))->toBeNull();
    expect(($this->statement)()['advance_minor'])->toBe(1250000);
});

it('adds up the takings by method and by person', function () {
    ($this->month)('2026-01');
    ($this->pay)(50000)->assertCreated();
    ($this->pay)(30000, ['method' => 'mobile', 'reference' => 'BK-77'])->assertCreated();
    ($this->pay)(20000, [], $this->checkerToken)->assertCreated();
    $voided = ($this->pay)(10000)->json('data');
    trustedOrgRule($this->w->c1, 'education_fees.void_needs_second_person', false);
    ($this->as)()->postJson(($this->fees)("receipts/{$voided['id']}/void"), ['reason' => 'Typo'])->assertCreated();

    $takings = ($this->as)()->getJson(($this->fees)('takings'))->assertOk()->json('data');
    expect($takings)->toMatchArray(['from' => '2026-01-05', 'total_minor' => 100000, 'voided_minor' => 10000])
        ->and($takings['by_method'])->toEqual(['cash' => 70000, 'mobile' => 30000])
        ->and($takings['by_collector'])->toEqual([$this->owner->id => 80000, $this->checker->id => 20000])
        ->and($takings['receipts'])->toHaveCount(4);
});

it('keeps the counter to the people allowed and inside the institution', function () {
    ($this->month)('2026-01');
    $receipt = ($this->pay)(50000)->json('data');
    $viewer = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education_fees.view', 'education.view']));
    $viewerToken = orgToken($viewer, $this->w->c1);
    ($this->as)($viewerToken)->getJson(($this->fees)("receipts/{$receipt['id']}"))->assertOk();
    ($this->pay)(1000, [], $viewerToken)->assertForbidden();
    ($this->as)($viewerToken)->postJson(($this->fees)("receipts/{$receipt['id']}/void"), ['reason' => 'x y z'])->assertForbidden();

    foreach ([$this->w->c2, $this->w->c4] as $other) {
        $stranger = orgToken(createMember($other), $other);
        $this->asToken($stranger)->getJson(($this->fees)("receipts/{$receipt['id']}", $other))->assertNotFound();
        $this->asToken($stranger)->postJson(($this->fees)('receipts', $other), ['student_id' => $this->rahim['id'], 'amount_minor' => 100, 'method' => 'cash', 'received_on' => '2026-01-05'])->assertNotFound();
    }
});
