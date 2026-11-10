<?php

use App\Models\User;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\PatientBilling\Contracts\PatientCharges;
use Modules\PatientBilling\Events\BillFinalized;
use Modules\PatientBilling\Events\PaymentReceived;
use Modules\PatientBilling\Models\Payment;
use Modules\PatientBilling\Services\BillTotals;

/*
 * H-1b Patient billing: a bill from services to final, payments (full, part,
 * advance), discounts with approval, panels within their credit limit,
 * refunds as separate records, the cash day and its difference, other
 * departments' charges, the books, the portal, rules by level, permissions,
 * modules off, and tenant/partner isolation.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    setPlan($this->w->g1, 'business');
    foreach (['patients', 'patient_billing'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    $this->ownerUser = createMember($this->w->c1);
    $this->owner = orgToken($this->ownerUser, $this->w->c1);
    $role = fn (array $permissions, string $name) => orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, $permissions, $name)), $this->w->c1);
    $this->cashier = $role(['patient_billing.view', 'patient_billing.collect'], 'Cashier');
    $this->approver = $role(['patient_billing.view', 'patient_billing.collect', 'patient_billing.approve'], 'Approver');
    $this->supervisor = $role(['patient_billing.view', 'patient_billing.collect', 'patient_billing.close_day'], 'Supervisor');
    $this->viewer = $role(['patient_billing.view'], 'Viewer');
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/patient-billing/{$path}";
    $this->patient = fn (string $name = 'Rahim Uddin', string $mobile = '01712345678', $token = null, $unit = null) => $this->asToken($token ?? $this->owner)
        ->postJson('/api/organizations/'.($unit ?? $this->w->c1)->id.'/patients/patients', ['name' => $name, 'sex' => 'male', 'age_years' => 40, 'mobile' => $mobile])->assertCreated()->json('data');
    $this->service = fn (string $code, int $price, $token = null, $unit = null) => $this->asToken($token ?? $this->owner)->postJson(($this->api)('services', $unit), [
        'code' => $code, 'name' => ['en' => "Service {$code}", 'bn' => "সেবা {$code}"], 'category' => 'test', 'price_minor' => $price, 'is_active' => true,
    ])->assertCreated()->json('data');
    $this->rahim = ($this->patient)();
    $this->cons = ($this->service)('CONS', 50000);
    $this->cbc = ($this->service)('CBC', 40000);
    // A bill with a consultation and two blood counts: 1,300.00.
    $this->bill = function (array $data = [], ?string $token = null) {
        $bill = $this->asToken($token ?? $this->cashier)->postJson(($this->api)('bills'), ['patient_id' => $this->rahim['id'], ...$data])->assertCreated()->json('data');
        $this->asToken($token ?? $this->cashier)->postJson(($this->api)("bills/{$bill['id']}/lines"), ['service_id' => $this->cons['id']])->assertCreated();

        return $this->asToken($token ?? $this->cashier)->postJson(($this->api)("bills/{$bill['id']}/lines"), ['service_id' => $this->cbc['id'], 'quantity' => 2])->assertCreated()->json('data');
    };
    $this->pay = fn (string $billId, int $amount, string $method = 'cash', ?string $op = null, ?string $token = null) => $this->asToken($token ?? $this->cashier)
        ->postJson(($this->api)('payments'), ['op_id' => $op ?? (string) Str::ulid(), 'kind' => 'payment', 'bill_id' => $billId, 'method' => $method, 'amount_minor' => $amount]);
    $this->openDay = fn (?string $token = null, int $float = 0) => $this->asToken($token ?? $this->cashier)->postJson(($this->api)('cash-days/open'), ['opening_float_minor' => $float])->assertCreated()->json('data');
});

it('bills a patient from services to final, takes part and full payments once each, and closes the cash day', function () {
    Event::fake([BillFinalized::class, PaymentReceived::class]);
    $bill = ($this->bill)();
    expect($bill['number'])->toBe('B000001')->and($bill['subtotal_minor'])->toBe(130000)->and($bill['total_minor'])->toBe(130000)->and($bill['lines'])->toHaveCount(2);

    ($this->pay)($bill['id'], 100000)->assertStatus(409)->assertJsonPath('code', 'no_cash_day');
    ($this->openDay)(null, 10000);
    $first = ($this->pay)($bill['id'], 100000, 'cash', 'tap-1')->assertCreated()->assertJsonPath('data.number', 'MR000001')->json('data');
    ($this->pay)($bill['id'], 100000, 'cash', 'tap-1')->assertOk()->assertJsonPath('data.id', $first['id']);
    ($this->pay)($bill['id'], 40000, 'card')->assertUnprocessable()->assertJsonPath('code', 'overpay');

    $final = $this->asToken($this->cashier)->postJson(($this->api)("bills/{$bill['id']}/finalize"), ['base_version' => $this->asToken($this->cashier)->getJson(($this->api)("bills/{$bill['id']}"))->json('data.version')])
        ->assertOk()->assertJsonPath('data.status', 'final')->assertJsonPath('data.due_minor', 30000)->json('data');
    ($this->pay)($bill['id'], 30000, 'card')->assertCreated();
    $this->asToken($this->cashier)->getJson(($this->api)("bills/{$bill['id']}"))->assertJsonPath('data.due_minor', 0)->assertJsonPath('data.paid_minor', 130000);
    $this->asToken($this->cashier)->postJson(($this->api)("bills/{$bill['id']}/lines"), ['service_id' => $this->cons['id']])->assertStatus(409)->assertJsonPath('code', 'bill_not_open');
    Event::assertDispatched(BillFinalized::class, fn ($event) => $event->billId === $bill['id'] && $event->totalMinor === 130000);
    Event::assertDispatchedTimes(PaymentReceived::class, 2);

    // The bill is in the patient's history (no amounts).
    $history = $this->asToken($this->owner)->getJson("/api/organizations/{$this->w->c1->id}/patients/patients/{$this->rahim['id']}/timeline")->json('data.entries');
    expect(collect($history)->pluck('title'))->toContain('Bill B000001');

    // Float 100.00 + cash 1,000.00 = 1,100.00 expected; counted right, the day closes.
    $days = $this->asToken($this->cashier)->getJson(($this->api)('cash-days'))->assertOk()->json('data');
    expect($days['current']['expected_minor'])->toBe(110000);
    $this->asToken($this->cashier)->postJson(($this->api)("cash-days/{$days['current']['id']}/close"), ['counted_minor' => 110000, 'base_version' => $days['current']['version']])
        ->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.variance_minor', 0);
    expect($final['number'])->toBe('B000001');
});

it('applies a small discount at once and waits for someone else on a big one', function () {
    $bill = ($this->bill)();
    $this->asToken($this->cashier)->postJson(($this->api)("bills/{$bill['id']}/discount"), ['amount_minor' => 13000, 'reason' => 'Staff family'])->assertOk()
        ->assertJsonPath('data.discount_minor', 13000)->assertJsonPath('data.total_minor', 117000);
    $this->asToken($this->cashier)->postJson(($this->api)("bills/{$bill['id']}/discount"), ['amount_minor' => 200000, 'reason' => 'Too much'])->assertUnprocessable()->assertJsonPath('code', 'discount_too_big');
    $asked = $this->asToken($this->approver)->postJson(($this->api)("bills/{$bill['id']}/discount"), ['amount_minor' => 30000, 'reason' => 'Poor patient'])->assertStatus(202)
        ->assertJsonPath('waiting_approval', true)->assertJsonPath('data.discount_minor', 13000)->json('data');
    $this->asToken($this->cashier)->postJson(($this->api)("bills/{$bill['id']}/finalize"), ['base_version' => $asked['version']])->assertStatus(409)->assertJsonPath('code', 'discount_waiting');

    $request = $this->asToken($this->approver)->getJson(($this->api)('approvals'))->assertOk()->json('data.discounts.0');
    expect($request['percent_bp'])->toBe(2308)->and($request['patient']['mrn'])->toBe($this->rahim['mrn']);
    $this->asToken($this->approver)->postJson(($this->api)("discount-requests/{$request['id']}/decide"), ['approve' => true])->assertForbidden()->assertJsonPath('code', 'own_request');
    $this->asToken($this->cashier)->postJson(($this->api)("discount-requests/{$request['id']}/decide"), ['approve' => true])->assertForbidden();
    $this->asToken($this->owner)->postJson(($this->api)("discount-requests/{$request['id']}/decide"), ['approve' => true])->assertOk()->assertJsonPath('data.status', 'approved');

    $now = $this->asToken($this->cashier)->getJson(($this->api)("bills/{$bill['id']}"))->assertJsonPath('data.discount_minor', 30000)->assertJsonPath('data.total_minor', 100000)->json('data');
    // The lines carry the discount between them and add up to the bill.
    expect(array_sum(array_column($now['lines'], 'discount_minor')))->toBe(30000)->and(array_sum(array_column($now['lines'], 'total_minor')))->toBe(100000);
    $this->asToken($this->cashier)->postJson(($this->api)("bills/{$bill['id']}/finalize"), ['base_version' => $now['version']])->assertOk();
});

it('puts what a panel owes on the panel, within its credit limit and with its discount', function () {
    $panel = $this->asToken($this->owner)->postJson(($this->api)('panels'), ['name' => 'Garments Ltd', 'kind' => 'corporate', 'credit_limit_minor' => 50000, 'discount_percent' => 10, 'is_active' => true])->assertCreated()->json('data');
    $bill = ($this->bill)(['panel_id' => $panel['id']]);
    expect($bill['panel_discount_minor'])->toBe(13000)->and($bill['total_minor'])->toBe(117000);
    $this->asToken($this->cashier)->postJson(($this->api)("bills/{$bill['id']}/finalize"), ['base_version' => $bill['version']])->assertUnprocessable()
        ->assertJsonPath('code', 'panel_over_limit')->assertJsonPath('left_minor', 50000);
    ($this->pay)($bill['id'], 70000, 'card')->assertCreated();
    $bill = $this->asToken($this->cashier)->getJson(($this->api)("bills/{$bill['id']}"))->json('data');
    $this->asToken($this->cashier)->postJson(($this->api)("bills/{$bill['id']}/finalize"), ['base_version' => $bill['version']])->assertOk()
        ->assertJsonPath('data.panel_due_minor', 47000)->assertJsonPath('data.panel_name', 'Garments Ltd');

    // A second bill would go over what is left (3.00).
    $second = ($this->bill)(['panel_id' => $panel['id']]);
    ($this->pay)($second['id'], 116000, 'card')->assertCreated();
    $second = $this->asToken($this->cashier)->getJson(($this->api)("bills/{$second['id']}"))->json('data');
    $this->asToken($this->cashier)->postJson(($this->api)("bills/{$second['id']}/finalize"), ['base_version' => $second['version']])->assertOk();
});

it('takes advances, pays from them, and refunds as separate records only with approval', function () {
    $this->asToken($this->cashier)->postJson(($this->api)('payments'), ['op_id' => 'adv-1', 'kind' => 'advance', 'patient_id' => $this->rahim['id'], 'method' => 'advance', 'amount_minor' => 20000])
        ->assertUnprocessable()->assertJsonPath('code', 'bad_method');
    $this->asToken($this->cashier)->postJson(($this->api)('payments'), ['op_id' => 'adv-1', 'kind' => 'advance', 'patient_id' => $this->rahim['id'], 'method' => 'card', 'amount_minor' => 20000])->assertCreated();
    $bill = ($this->bill)();
    expect($bill['advance_balance_minor'])->toBe(20000);
    ($this->pay)($bill['id'], 15000, 'advance')->assertCreated();
    ($this->pay)($bill['id'], 10000, 'advance')->assertUnprocessable()->assertJsonPath('code', 'advance_short')->assertJsonPath('balance_minor', 5000);
    ($this->pay)($bill['id'], 85000, 'mobile')->assertCreated();

    $refund = fn (int $amount, string $method, $token) => $this->asToken($token)->postJson(($this->api)("bills/{$bill['id']}/refund"), [
        'op_id' => (string) Str::ulid(), 'method' => $method, 'amount_minor' => $amount, 'reason' => 'Test not done',
    ]);
    $refund(10000, 'mobile', $this->cashier)->assertForbidden();
    $refund(10000, 'cash', $this->approver)->assertStatus(409)->assertJsonPath('code', 'no_cash_day');
    $refund(10000, 'mobile', $this->approver)->assertCreated()->assertJsonPath('data.number', 'RF000001')->assertJsonPath('data.kind', 'refund');
    $refund(95000, 'mobile', $this->approver)->assertUnprocessable()->assertJsonPath('code', 'refund_too_big')->assertJsonPath('max_minor', 90000);
    $refund(5000, 'advance', $this->approver)->assertCreated();

    $now = $this->asToken($this->cashier)->getJson(($this->api)("bills/{$bill['id']}"))->json('data');
    expect($now['paid_minor'])->toBe(100000)->and($now['refunded_minor'])->toBe(15000)->and($now['kept_minor'])->toBe(85000)
        ->and($now['advance_balance_minor'])->toBe(10000)->and($now['payments'])->toHaveCount(4);
    // Money records are never changed or removed.
    expect(fn () => Payment::query()->withoutGlobalScopes()->first()->forceFill(['amount_minor' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => Payment::query()->withoutGlobalScopes()->first()->delete())->toThrow(LogicException::class);
});

it('voids only an open bill nobody paid, and keeps dues to what the branch allows', function () {
    $bill = ($this->bill)();
    $this->asToken($this->cashier)->deleteJson(($this->api)("bills/{$bill['id']}/lines/{$bill['lines'][0]['id']}"))->assertOk()->assertJsonPath('data.total_minor', 80000);
    $bill = $this->asToken($this->cashier)->getJson(($this->api)("bills/{$bill['id']}"))->json('data');
    $this->asToken($this->cashier)->postJson(($this->api)("bills/{$bill['id']}/void"), ['reason' => 'Wrong patient', 'base_version' => $bill['version']])->assertOk()->assertJsonPath('data.status', 'void');
    ($this->pay)($bill['id'], 100, 'card')->assertStatus(409);

    $paid = ($this->bill)();
    ($this->pay)($paid['id'], 1000, 'card')->assertCreated();
    $paid = $this->asToken($this->cashier)->getJson(($this->api)("bills/{$paid['id']}"))->json('data');
    $this->asToken($this->cashier)->postJson(($this->api)("bills/{$paid['id']}/void"), ['reason' => 'Changed mind', 'base_version' => $paid['version']])->assertStatus(409)->assertJsonPath('code', 'bill_paid');

    orgRule($this->w->c1, 'patient_billing.allow_due', false);
    $this->asToken($this->cashier)->postJson(($this->api)("bills/{$paid['id']}/finalize"), ['base_version' => $paid['version']])->assertUnprocessable()
        ->assertJsonPath('code', 'must_pay_in_full')->assertJsonPath('due_minor', 129000);
    $empty = $this->asToken($this->cashier)->postJson(($this->api)('bills'), ['patient_id' => $this->rahim['id']])->assertCreated()->json('data');
    $this->asToken($this->cashier)->postJson(($this->api)("bills/{$empty['id']}/finalize"), ['base_version' => $empty['version']])->assertUnprocessable()->assertJsonPath('code', 'bill_empty');
});

it('sends a cash day with a difference over the limit to a supervisor who is not the cashier', function () {
    trustedOrgRule($this->w->c1, 'patient_billing.cash_variance_allowed', 500);
    $day = ($this->openDay)($this->supervisor);
    $bill = ($this->bill)([], $this->supervisor);
    ($this->pay)($bill['id'], 50000, 'cash', null, $this->supervisor)->assertCreated();
    $this->asToken($this->supervisor)->postJson(($this->api)('cash-days/open'), [])->assertStatus(409)->assertJsonPath('code', 'day_already_open');
    $this->asToken($this->cashier)->postJson(($this->api)("cash-days/{$day['id']}/close"), ['counted_minor' => 49000, 'base_version' => 1])->assertForbidden();
    $closed = $this->asToken($this->supervisor)->postJson(($this->api)("cash-days/{$day['id']}/close"), ['counted_minor' => 49000, 'base_version' => 1])->assertOk()
        ->assertJsonPath('data.status', 'review')->assertJsonPath('data.variance_minor', -1000)->json('data');
    $this->asToken($this->supervisor)->postJson(($this->api)("cash-days/{$closed['id']}/review"), ['note' => 'Accepted'])->assertForbidden()->assertJsonPath('code', 'own_request');
    $this->asToken($this->cashier)->postJson(($this->api)("cash-days/{$closed['id']}/review"), ['note' => 'Accepted'])->assertForbidden();
    $this->asToken($this->owner)->postJson(($this->api)("cash-days/{$closed['id']}/review"), ['note' => 'Short by 10, counted twice'])->assertOk()->assertJsonPath('data.status', 'closed');
    // A cashier sees only their own days; a supervisor everyone's.
    expect($this->asToken($this->cashier)->getJson(($this->api)('cash-days'))->json('data.days'))->toBe([])
        ->and(count($this->asToken($this->owner)->getJson(($this->api)('cash-days'))->json('data.days')))->toBe(1);
});

it('lets other departments charge a patient once per order through PatientCharges', function () {
    $actor = User::query()->findOrFail($this->ownerUser->id);
    $first = app(PatientCharges::class)->add($this->w->c1, $this->w->c1, $this->rahim['id'], 'diagnostics', 'order', 'ORD-1', 'CBC', 1000, $actor);
    $again = app(PatientCharges::class)->add($this->w->c1, $this->w->c1, $this->rahim['id'], 'diagnostics', 'order', 'ORD-1', 'CBC', 1000, $actor);
    $other = app(PatientCharges::class)->add($this->w->c1, $this->w->c1, $this->rahim['id'], 'diagnostics', 'order', 'ORD-2', 'CONS', 1000, $actor);
    expect($again)->toBe($first)->and($other['bill_id'])->toBe($first['bill_id']);
    $bill = $this->asToken($this->cashier)->getJson(($this->api)("bills/{$first['bill_id']}"))->assertOk()->json('data');
    expect($bill['total_minor'])->toBe(90000)->and(collect($bill['lines'])->pluck('source_module')->unique()->all())->toBe(['diagnostics']);
});

it('writes bills and money into the books when the company keeps them', function () {
    toggles()->enable($this->w->g1, 'accounting', 'Test setup');
    setUpBooks($this, $this->owner, $this->w->c1)->assertCreated();
    ($this->openDay)();
    $bill = ($this->bill)();
    ($this->pay)($bill['id'], 30000, 'cash')->assertCreated();
    $this->asToken($this->cashier)->postJson(($this->api)("bills/{$bill['id']}/finalize"), ['base_version' => $this->asToken($this->cashier)->getJson(($this->api)("bills/{$bill['id']}"))->json('data.version')])->assertOk();

    $journals = DB::table('patient_billing_bills')->whereNotNull('journal_id')->count() + DB::table('patient_billing_payments')->whereNotNull('journal_id')->count();
    expect($journals)->toBe(2);
    $lines = DB::table('acc_journal_lines')->whereIn('journal_id', [...DB::table('patient_billing_bills')->pluck('journal_id'), ...DB::table('patient_billing_payments')->pluck('journal_id')])->get();
    expect((int) $lines->sum('debit_minor'))->toBe((int) $lines->sum('credit_minor'))->and((int) $lines->sum('debit_minor'))->toBe(160000);
});

it('shows portal patients only their own final bills', function () {
    toggles()->enable($this->w->g1, 'client_portal', 'Test setup');
    $mine = ($this->bill)();
    $this->asToken($this->cashier)->postJson(($this->api)("bills/{$mine['id']}/finalize"), ['base_version' => $mine['version']])->assertOk();
    ($this->bill)();
    $karima = ($this->patient)('Karima Begum', '01812345678');
    $hers = $this->asToken($this->cashier)->postJson(($this->api)('bills'), ['patient_id' => $karima['id']])->assertCreated()->json('data');

    $person = User::factory()->create(['email_verified_at' => now()]);
    app(AddMember::class)->handle($this->w->c1, $person, MembershipType::Portal, AccessScope::Own);
    $membership = OrganizationMembership::query()->where('user_id', $person->id)->where('organization_id', $this->w->c1->id)->sole();
    (new PortalLink)->forceFill([
        'organization_id' => $this->w->c1->id, 'membership_id' => $membership->id, 'user_id' => $person->id,
        'subject_type' => 'patients.patient', 'subject_id' => $this->rahim['id'], 'relation' => 'self', 'status' => PortalLink::ACTIVE, 'linked_via' => 'invitation',
    ])->save();
    $portal = orgToken($person, $this->w->c1);
    $this->withHeader('Origin', config('app.url'));

    $seen = $this->asToken($portal)->getJson('/api/portal/patient-billing/bills')->assertOk()->json('data.bills');
    expect(collect($seen)->pluck('id')->all())->toBe([$mine['id']])->and($seen[0]['payments'])->toBe([])->and($seen[0])->not->toHaveKey('patient');
    $this->asToken($portal)->getJson(($this->api)("bills/{$hers['id']}"))->assertForbidden();
});

it('takes numbers and the discount limit from the level that sets them', function () {
    orgRule($this->w->g1, 'patient_billing.number_prefixes', ['bill' => 'HB', 'receipt' => 'HR', 'refund' => 'HF']);
    $bill = ($this->bill)();
    expect($bill['number'])->toBe('HB000001');
    trustedOrgRule($this->w->c1, 'patient_billing.discount_approval_above_percent', 25);
    $this->asToken($this->cashier)->postJson(($this->api)("bills/{$bill['id']}/discount"), ['amount_minor' => 30000, 'reason' => 'Under 25%'])->assertOk()->assertJsonPath('data.discount_minor', 30000);
    expect(ruleFor('patient_billing.discount_approval_above_percent', $this->w->c2))->toBe(10);
});

it('keeps each permission to its own work and answers 403 when billing or patients is off', function () {
    $this->asToken($this->viewer)->postJson(($this->api)('bills'), ['patient_id' => $this->rahim['id']])->assertForbidden();
    $this->asToken($this->viewer)->getJson(($this->api)('approvals'))->assertForbidden();
    $this->asToken($this->viewer)->getJson(($this->api)('patients?q=rahim'))->assertForbidden();
    $this->asToken($this->cashier)->getJson(($this->api)('patients?q=rahim'))->assertOk()->assertJsonPath('data.0.id', $this->rahim['id']);
    $this->asToken($this->cashier)->postJson(($this->api)('services'), ['code' => 'X1', 'name' => ['en' => 'X'], 'category' => 'other', 'price_minor' => 1])->assertForbidden();
    $this->asToken($this->viewer)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can.collect', false);
    $bill = ($this->bill)();
    $this->asToken($this->viewer)->getJson(($this->api)("bills/{$bill['id']}/receipt"))->assertOk()->assertJsonPath('data.number', 'B000001');
    // A service on a bill is kept (not in use any more instead of removed).
    $this->asToken($this->owner)->deleteJson(($this->api)("services/{$this->cons['id']}?base_version=1"))->assertStatus(409)->assertJsonPath('code', 'in_use');

    toggles()->disable($this->w->c1, 'patient_billing', 'Test');
    $this->asToken($this->owner)->getJson(($this->api)('bills'))->assertForbidden();
    toggles()->enable($this->w->c1, 'patient_billing', 'Test');
    // Patients off (billing needs it, so it goes off with it once confirmed).
    toggles()->disable($this->w->c1, 'patients', 'Test', confirm: true);
    $this->asToken($this->owner)->getJson(($this->api)('bills'))->assertForbidden();
});

it('never shows or touches another company\'s or another partner\'s bills', function () {
    $bill = ($this->bill)();
    $sister = orgToken(createMember($this->w->c2), $this->w->c2);
    $this->asToken($sister)->getJson(($this->api)("bills/{$bill['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($sister)->getJson(($this->api)("bills/{$bill['id']}"))->assertNotFound();
    $this->asToken($sister)->postJson(($this->api)('bills', $this->w->c2), ['patient_id' => $this->rahim['id']])->assertNotFound()->assertJsonPath('code', 'patient_not_found');
    $this->asToken($sister)->postJson(($this->api)('payments', $this->w->c2), ['op_id' => 'x', 'kind' => 'payment', 'bill_id' => $bill['id'], 'method' => 'card', 'amount_minor' => 100])->assertNotFound();

    setPlan($this->w->g3, 'business');
    foreach (['patients', 'patient_billing'] as $module) {
        toggles()->enable($this->w->g3, $module, 'Test setup');
    }
    $elsewhere = orgToken(createMember($this->w->c4), $this->w->c4);
    $this->asToken($elsewhere)->getJson(($this->api)("bills/{$bill['id']}", $this->w->c4))->assertNotFound();
    $this->asToken($elsewhere)->postJson(($this->api)("bills/{$bill['id']}/refund", $this->w->c4), ['op_id' => 'y', 'method' => 'card', 'amount_minor' => 1, 'reason' => 'Try it'])->assertNotFound();
    expect($this->asToken($elsewhere)->getJson(($this->api)('bills', $this->w->c4))->json('meta.total'))->toBe(0);
});

it('works out totals that always add up, with VAT inside or on top', function () {
    $lines = [['quantity_milli' => 1000, 'unit_price_minor' => 10000, 'tax_rate_bp' => 1500], ['quantity_milli' => 3000, 'unit_price_minor' => 3333, 'tax_rate_bp' => 0]];
    $inside = BillTotals::of($lines, 10, 1001, true);
    expect($inside['subtotal'])->toBe(19999)->and($inside['panel_discount'])->toBe(2000)->and($inside['discount'])->toBe(1001)
        ->and(array_sum(array_column($inside['lines'], 'discount')))->toBe(3001)
        ->and($inside['total'])->toBe(19999 - 3001)->and($inside['tax'])->toBeGreaterThan(0);
    $onTop = BillTotals::of($lines, 0, 0, false);
    expect($onTop['tax'])->toBe(1500)->and($onTop['total'])->toBe(19999 + 1500);
    expect(BillTotals::share(10, [1, 1, 1]))->toBe([4, 3, 3])->and(BillTotals::share(5, [0, 0]))->toBe([0, 0]);
});
