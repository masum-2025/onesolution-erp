<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Modules\Attendance\Services\AttendanceSummary;
use Modules\Leave\Models\LeaveEntry;
use Modules\Leave\Payroll\EncashedDays;
use Modules\Leave\Services\LeavePolicy;
use Modules\Payroll\Services\PayCalculator;

/*
 * LV-1: kinds of leave (the usual ones from the rule), leave coming in,
 * asking (weekends and holidays not counted), approval steps (the manager
 * first, never one's own), the balance ledger, leave without pay beyond the
 * balance, cancelling, attendance showing the leave and Payroll's summary
 * the unpaid part. Company C1: Asia/Dhaka, weekends Friday and Saturday;
 * today is Thursday 15 October 2026.
 */

beforeEach(function () {
    Queue::fake(); // keep messages queued: a sent message's values are wiped after delivery
    $this->travelTo(CarbonImmutable::parse('2026-10-15 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'attendance', 'Test setup');
        toggles()->enable($group, 'leave', 'Test setup');
    }
    trustedOrgRule($this->w->c1, 'attendance.weekend_days', ['fri', 'sat']);

    $this->hrUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'hrm.manage', 'attendance.view', 'leave.view', 'leave.approve', 'leave.manage'], 'HR'));
    $this->hr = orgToken($this->hrUser, $this->w->c1);
    $this->bossUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['leave.view', 'leave.approve', 'leave.request'], 'Manager'));
    $this->boss = orgToken($this->bossUser, $this->w->c1);
    $this->otherApprover = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['leave.view', 'leave.approve'], 'Approver')), $this->w->c1);
    $this->workerUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['leave.request'], 'Worker'));
    $this->worker = orgToken($this->workerUser, $this->w->c1);

    $hire = fn (array $data) => hireVia($this, $this->w->token, $this->w->b1, ['joined_on' => '2026-01-01', ...$data])->assertCreated()->json('data');
    $link = fn (array $employee, User $user) => $this->asToken($this->hr)
        ->putJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$employee['id']}/login", ['base_version' => $employee['version'], 'user_id' => $user->id])->assertOk();
    $this->mahmud = $hire(['full_name' => 'Mahmud Hasan']);
    $link($this->mahmud, $this->bossUser);
    $this->rahima = $hire(['full_name' => 'Rahima Akter', 'gender' => 'female', 'manager_id' => $this->mahmud['id']]);
    $link($this->rahima, $this->workerUser);

    $this->api = fn (string $path) => "/api/organizations/{$this->w->c1->id}/leave/{$path}";
    $this->asToken($this->hr)->postJson(($this->api)('types/defaults'))->assertOk();
    $types = collect($this->asToken($this->hr)->getJson(($this->api)('types'))->assertOk()->json('data'))->keyBy('code');
    $this->casual = $types['CL'];
    Artisan::call('leave:accrue');

    $this->ask = fn (array $data, ?string $token = null) => $this->asToken($token ?? $this->worker)
        ->postJson(($this->api)('me/requests'), ['type_id' => $this->casual['id'], 'reason' => 'Family wedding', ...$data]);
    $this->step = fn (string $token, array $request, string $step, array $data = []) => $this->asToken($token)
        ->postJson(($this->api)("requests/{$request['id']}/{$step}"), ['base_version' => $request['version'], ...$data]);
    $this->myBalance = fn (string $code = 'CL') => collect($this->asToken($this->worker)->getJson(($this->api)('me'))->assertOk()->json('data.balances'))->firstWhere('code', $code);
});

it('works leave out from facts: leave years, days counted, shares for joiners', function () {
    $day = fn (string $date) => CarbonImmutable::parse($date, 'UTC');
    expect(LeavePolicy::yearOf($day('2026-06-30'), 7))->toBe(2025)
        ->and(LeavePolicy::yearOf($day('2026-07-01'), 7))->toBe(2026)
        ->and(LeavePolicy::yearEnd(2026, 7)->toDateString())->toBe('2027-06-30');

    $weekend = fn (CarbonImmutable $d) => in_array($d->dayOfWeek, [5, 6], true);
    // Thursday afternoon to Monday morning, the weekend left out: 1 + 2 + 1 halves.
    expect(LeavePolicy::days($day('2026-10-15'), $day('2026-10-19'), true, true, $weekend, false))->toBe([
        ['date' => '2026-10-15', 'halves' => 1], ['date' => '2026-10-18', 'halves' => 2], ['date' => '2026-10-19', 'halves' => 1],
    ]);
    expect(LeavePolicy::yearlyShare(20, $day('2026-10-01'), 2026, 1))->toBe(5)
        ->and(LeavePolicy::monthlyTarget(30, $day('2025-05-01'), $day('2026-10-15'), 2026, 1))->toBe(25)
        ->and(LeavePolicy::earnedTarget(40, 18))->toBe(4)
        ->and(LeavePolicy::carried(31, 20))->toBe(20)
        ->and(LeavePolicy::payByDay([['date' => 'a', 'halves' => 2], ['date' => 'b', 'halves' => 2], ['date' => 'c', 'halves' => 1]], 3))
        ->toBe(['a' => ['paid' => true, 'half' => false], 'b' => ['paid' => false, 'half' => false], 'c' => ['paid' => false, 'half' => true]]);
});

it('adds the usual kinds of leave once and gives the year\'s leave once', function () {
    $this->asToken($this->hr)->postJson(($this->api)('types/defaults'))->assertOk()->assertJsonPath('data.added', 0);
    Artisan::call('leave:accrue');

    expect(($this->myBalance)())->toMatchArray(['given_halves' => 20, 'balance_halves' => 20, 'available_halves' => 20])
        ->and(($this->myBalance)('AL'))->toMatchArray(['given_halves' => 25]) // 15 days a year, ten months begun
        ->and(LeaveEntry::query()->withoutGlobalScopes()->where('employee_id', $this->rahima['id'])->where('kind', 'accrual')->where('source', 'year-2026')->count())->toBe(2);

    // Only HR changes kinds of leave.
    $this->asToken($this->worker)->postJson(($this->api)('types'), ['code' => 'XL', 'name' => ['en' => 'Extra'], 'paid' => true, 'accrual' => 'yearly'])->assertForbidden();
    $this->asToken($this->hr)->postJson(($this->api)('types'), ['code' => 'CL', 'name' => ['en' => 'Again'], 'paid' => true, 'accrual' => 'yearly'])->assertConflict()->assertJsonPath('code', 'code_taken');
    $this->asToken($this->hr)->postJson(($this->api)('types'), ['code' => 'XL', 'name' => ['en' => 'Extra'], 'paid' => true, 'accrual' => 'yearly', 'colour' => 'red'])->assertUnprocessable();
});

it('counts working days only, and the manager approves first', function () {
    // Thursday 15th to Monday 19th: Friday and Saturday are the weekend, so three days.
    $request = ($this->ask)(['from_date' => '2026-10-15', 'to_date' => '2026-10-19', 'op_id' => 'phone-1'])->assertCreated()->json('data');
    expect($request)->toMatchArray(['halves' => 6, 'unpaid_halves' => 0, 'status' => 'pending', 'steps_needed' => 1])
        ->and(($this->ask)(['from_date' => '2026-10-15', 'to_date' => '2026-10-19', 'op_id' => 'phone-1'])->json('data.id'))->toBe($request['id'])
        ->and(($this->myBalance)())->toMatchArray(['balance_halves' => 20, 'waiting_halves' => 6, 'available_halves' => 14]);

    // The approvers are told; the list says who may decide.
    expect(NotificationDelivery::query()->where('notification_key', 'leave.requested')->pluck('user_id')->all())
        ->toContain($this->bossUser->id)->not->toContain($this->workerUser->id);
    $list = collect($this->asToken($this->otherApprover)->getJson(($this->api)('requests?status=pending'))->assertOk()->json('data'));
    expect($list->sole())->toMatchArray(['employee_name' => 'Rahima Akter', 'type_code' => 'CL', 'can_decide' => false]);

    // Not the employee, not another approver while the manager may decide: the manager.
    ($this->step)($this->worker, $request, 'approve')->assertForbidden();
    ($this->step)($this->otherApprover, $request, 'approve')->assertForbidden()->assertJsonPath('code', 'not_your_step');
    $approved = ($this->step)($this->boss, $request, 'approve', ['note' => 'Enjoy'])->assertOk()->json('data');
    expect($approved['status'])->toBe('approved')
        ->and(($this->myBalance)())->toMatchArray(['balance_halves' => 14, 'taken_halves' => 6, 'available_halves' => 14])
        ->and(AuditLog::query()->where('action', 'leave.approved')->sole()->reason)->toBe('Enjoy')
        ->and(NotificationDelivery::query()->where('notification_key', 'leave.decided')->sole()->user_id)->toBe($this->workerUser->id);
    ($this->step)($this->boss, $approved, 'approve')->assertConflict()->assertJsonPath('code', 'decided');

    // Attendance shows the day as leave, not absent.
    $day = $this->asToken($this->hr)->getJson("/api/organizations/{$this->w->c1->id}/attendance/days?from=2026-10-18&to=2026-10-18&employee_id={$this->rahima['id']}")->json('data.0');
    expect($day)->toMatchArray(['status' => 'leave', 'leave' => 'paid']);
});

it('refuses more than the balance, or takes the rest without pay where the rule says so', function () {
    // Sunday 18 October to Monday 2 November: twelve working days, ten days left.
    $long = ['from_date' => '2026-10-18', 'to_date' => '2026-11-02'];
    ($this->ask)($long)->assertUnprocessable()->assertJsonPath('code', 'not_enough')->assertJsonPath('available_halves', 20);

    trustedOrgRule($this->w->c1, 'leave.beyond_balance', 'unpaid');
    $request = ($this->ask)($long)->assertCreated()->json('data');
    expect($request)->toMatchArray(['halves' => 24, 'unpaid_halves' => 4]);
    ($this->step)($this->boss, $request, 'approve')->assertOk();
    expect(($this->myBalance)())->toMatchArray(['balance_halves' => 0, 'taken_halves' => 20]);

    // The last two days are without pay: Payroll reads them from Attendance's summary.
    $summary = app(AttendanceSummary::class)->forPeriod($this->w->c1, [$this->rahima['id']], CarbonImmutable::parse('2026-10-29'), CarbonImmutable::parse('2026-11-02'))[$this->rahima['id']];
    expect($summary['unpaid_leave_halves'])->toBe(4)
        ->and($summary['days']['unpaid_leave'])->toBe(2)
        ->and($summary['days']['leave'])->toBe(1);
});

it('refuses overlaps, a request across two leave years, days off only and both halves of one day', function () {
    ($this->ask)(['from_date' => '2026-10-18', 'to_date' => '2026-10-19'])->assertCreated();
    ($this->ask)(['from_date' => '2026-10-19', 'to_date' => '2026-10-20'])->assertConflict()->assertJsonPath('code', 'overlaps');
    ($this->ask)(['from_date' => '2026-12-31', 'to_date' => '2027-01-03'])->assertUnprocessable()->assertJsonPath('code', 'crosses_year');
    ($this->ask)(['from_date' => '2026-10-23', 'to_date' => '2026-10-24'])->assertUnprocessable()->assertJsonPath('code', 'no_working_days');
    ($this->ask)(['from_date' => '2026-10-21', 'to_date' => '2026-10-21', 'from_half' => true, 'to_half' => true])->assertUnprocessable()->assertJsonValidationErrors('to_half');
    ($this->ask)(['from_date' => '2026-09-01', 'to_date' => '2026-09-01'])->assertUnprocessable()->assertJsonValidationErrors('from_date');
    ($this->ask)(['from_date' => '2026-10-21', 'to_date' => '2026-10-20'])->assertUnprocessable()->assertJsonValidationErrors('to_date');
    ($this->ask)(['from_date' => '2026-10-21', 'to_date' => '2026-10-21', 'employee_id' => $this->mahmud['id']])->assertUnprocessable();
});

it('needs each step from a different person when the company asks for two', function () {
    trustedOrgRule($this->w->c1, 'leave.approval_steps', 2);
    $request = ($this->ask)(['from_date' => '2026-10-20', 'to_date' => '2026-10-20'])->assertCreated()->json('data');

    $request = ($this->step)($this->boss, $request, 'approve')->assertOk()->json('data');
    expect($request)->toMatchArray(['status' => 'pending', 'steps_done' => 1]);
    ($this->step)($this->boss, $request, 'approve')->assertForbidden()->assertJsonPath('code', 'not_your_step');
    ($this->step)($this->otherApprover, $request, 'approve')->assertOk()->assertJsonPath('data.status', 'approved');
    expect(AuditLog::query()->where('action', 'leave.step_approved')->count())->toBe(1);
});

it('gives the days back when approved leave is cancelled before it starts', function () {
    $request = ($this->ask)(['from_date' => '2026-10-20', 'to_date' => '2026-10-21'])->assertCreated()->json('data');
    $request = ($this->step)($this->boss, $request, 'approve')->assertOk()->json('data');
    expect(($this->myBalance)()['balance_halves'])->toBe(16);

    $this->asToken($this->worker)->postJson(($this->api)("me/requests/{$request['id']}/cancel"), ['base_version' => $request['version']])->assertUnprocessable();
    $this->asToken($this->worker)->postJson(($this->api)("me/requests/{$request['id']}/cancel"), ['base_version' => $request['version'], 'note' => 'Plans changed'])
        ->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(($this->myBalance)()['balance_halves'])->toBe(20);

    // Leave already started: only HR may cancel it.
    $today = ($this->ask)(['from_date' => '2026-10-15', 'to_date' => '2026-10-15'])->assertCreated()->json('data');
    $today = ($this->step)($this->boss, $today, 'approve')->assertOk()->json('data');
    $this->asToken($this->worker)->postJson(($this->api)("me/requests/{$today['id']}/cancel"), ['base_version' => $today['version'], 'note' => 'Came in after all'])
        ->assertConflict()->assertJsonPath('code', 'cannot_cancel');
    ($this->step)($this->hr, $today, 'cancel', ['note' => 'Came in after all'])->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('lets HR change a balance by hand with a reason, and ask on someone\'s behalf', function () {
    $adjust = fn (array $data) => $this->asToken($this->hr)->postJson(($this->api)('balances/adjust'), [
        'employee_id' => $this->rahima['id'], 'type_id' => $this->casual['id'], 'leave_year' => 2026, ...$data,
    ]);
    $adjust(['halves' => 0, 'note' => 'Nothing'])->assertUnprocessable()->assertJsonValidationErrors('halves');
    $adjust(['halves' => 4])->assertUnprocessable()->assertJsonValidationErrors('note');
    $adjust(['halves' => 4, 'note' => 'Worked on Victory Day'])->assertCreated()->assertJsonPath('data.balance_halves', 24);
    expect(AuditLog::query()->where('action', 'leave.balance_adjusted')->sole()->reason)->toBe('Worked on Victory Day');
    $this->asToken($this->boss)->postJson(($this->api)('balances/adjust'), ['employee_id' => $this->rahima['id'], 'type_id' => $this->casual['id'], 'leave_year' => 2026, 'halves' => 4, 'note' => 'Me too'])
        ->assertForbidden();

    // Asked by HR for her: HR may not approve it, the manager may.
    $request = $this->asToken($this->hr)->postJson(($this->api)('requests'), [
        'employee_id' => $this->rahima['id'], 'type_id' => $this->casual['id'], 'from_date' => '2026-10-20', 'to_date' => '2026-10-20', 'reason' => 'Phoned in sick',
    ])->assertCreated()->json('data');
    ($this->step)($this->hr, $request, 'approve')->assertForbidden()->assertJsonPath('code', 'own_request');
    ($this->step)($this->boss, $request, 'reject', ['note' => 'Use sick leave'])->assertOk()->assertJsonPath('data.status', 'rejected');
});

it('keeps leave to the company and switches off with the module', function () {
    $request = ($this->ask)(['from_date' => '2026-10-20', 'to_date' => '2026-10-20'])->assertCreated()->json('data');

    // A sister company's HR sees nothing of it.
    $sisterHr = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['leave.view', 'leave.approve', 'leave.manage'], 'HR')), $this->w->c2);
    expect($this->asToken($sisterHr)->getJson("/api/organizations/{$this->w->c2->id}/leave/requests")->assertOk()->json('data'))->toBe([]);
    expect($this->asToken($sisterHr)->getJson("/api/organizations/{$this->w->c2->id}/leave/requests/{$request['id']}")->status())->toBe(404);
    expect($this->asToken($sisterHr)->getJson("/api/organizations/{$this->w->c1->id}/leave/requests")->status())->toBeIn([403, 404]);

    // Off for the group: 403, nothing deleted; on again, it is all there.
    toggles()->disable($this->w->g1, 'leave', 'Not now');
    $this->asToken($this->worker)->getJson(($this->api)('me'))->assertForbidden();
    toggles()->enable($this->w->g1, 'leave', 'Back');
    expect(collect($this->asToken($this->worker)->getJson(($this->api)('me'))->assertOk()->json('data.requests'))->pluck('id')->all())->toBe([$request['id']]);
});

it('pays unused leave out once someone else approves it, and hands Payroll the days', function () {
    $annual = collect($this->asToken($this->hr)->getJson(($this->api)('types'))->json('data'))->firstWhere('code', 'AL');
    $ask = fn (array $data) => $this->asToken($this->hr)->postJson(($this->api)('encashments'), [
        'employee_id' => $this->rahima['id'], 'type_id' => $annual['id'], 'leave_year' => 2026, 'reason' => 'Year end', ...$data,
    ]);
    // Casual leave cannot be paid out; no more than is left (12.5 days of annual leave).
    $this->asToken($this->hr)->postJson(($this->api)('encashments'), ['employee_id' => $this->rahima['id'], 'type_id' => $this->casual['id'], 'leave_year' => 2026, 'halves' => 2, 'reason' => 'Year end'])
        ->assertUnprocessable()->assertJsonPath('code', 'not_encashable');
    $ask(['halves' => 26])->assertUnprocessable()->assertJsonPath('code', 'not_enough');
    $ask(['halves' => 4, 'colour' => 'red'])->assertUnprocessable();
    $this->asToken($this->boss)->postJson(($this->api)('encashments'), ['employee_id' => $this->rahima['id'], 'type_id' => $annual['id'], 'leave_year' => 2026, 'halves' => 4, 'reason' => 'Mine'])->assertForbidden();

    $payOut = $ask(['halves' => 4])->assertCreated()->json('data');
    expect(($this->myBalance)('AL'))->toMatchArray(['balance_halves' => 25, 'waiting_halves' => 4, 'available_halves' => 21]);

    // Money: never whoever asked.
    $decide = fn (string $token, string $step) => $this->asToken($token)->postJson(($this->api)("encashments/{$payOut['id']}/{$step}"), ['base_version' => $payOut['version']]);
    $decide($this->hr, 'approve')->assertForbidden()->assertJsonPath('code', 'own_encashment');
    $list = collect($this->asToken($this->boss)->getJson(($this->api)('encashments?status=pending'))->assertOk()->json('data'));
    expect($list->sole())->toMatchArray(['employee_name' => 'Rahima Akter', 'halves' => 4, 'can_decide' => true]);
    $decide($this->boss, 'approve')->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.pay_on', '2026-10-15');

    expect(($this->myBalance)('AL'))->toMatchArray(['given_halves' => 25, 'encashed_halves' => 4, 'balance_halves' => 21, 'available_halves' => 21])
        ->and(AuditLog::query()->where('action', 'leave.encashment_approved')->count())->toBe(1);

    // Payroll gets half days (never money) in the run whose days include the approval day.
    $days = app(EncashedDays::class)->daysToPay($this->w->c1, [$this->rahima['id']], CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
    expect($days[$this->rahima['id']][0])->toMatchArray(['code' => 'LEAVE_PAID_OUT', 'halves' => 4, 'taxable' => true])
        ->and($days[$this->rahima['id']][0]['name']['en'])->toBe('Leave paid out (Annual leave)')
        ->and(app(EncashedDays::class)->daysToPay($this->w->c1, [$this->rahima['id']], CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30')))->toBe([]);
});

it('pays the days out at a day of the basic or the gross', function () {
    $facts = ['basic' => 3000000, 'items' => [['code' => 'HOUSE', 'name' => ['en' => 'House'], 'kind' => 'earning', 'taxable' => true, 'prorated' => false, 'calc' => 'fixed', 'amount' => 1500000, 'rate_bp' => null]],
        'period_days' => 31, 'employed_days' => 31, 'absent_days' => 0, 'half_days' => 0, 'overtime_minutes' => 0, 'late_minutes' => 0, 'adjustments' => [],
        'paid_days' => [['code' => 'LEAVE_PAID_OUT', 'name' => ['en' => 'Leave paid out'], 'halves' => 4, 'taxable' => true]]];
    $rules = ['deduct_absence' => true, 'overtime_multiplier_bp' => 0, 'overtime_base' => 'basic', 'monthly_hours' => 208, 'late_policy' => 'none', 'tax_slabs' => [], 'encash_divisor' => 30];
    $line = fn (string $base) => collect(PayCalculator::slip($facts, [...$rules, 'encash_base' => $base])['lines'])->firstWhere('code', 'LEAVE_PAID_OUT');

    // Two days at 30,000.00 / 30, or at 45,000.00 / 30.
    expect($line('basic'))->toMatchArray(['kind' => 'earning', 'amount' => 200000, 'taxable' => true])
        ->and($line('gross')['amount'])->toBe(300000);
});

it('lists the people of the unit to pick on the leave screens, by name or code', function () {
    $people = collect($this->asToken($this->hr)->getJson(($this->api)('people?q=rahima'))->assertOk()->json('data'));
    expect($people->sole())->toMatchArray(['id' => $this->rahima['id'], 'name' => 'Rahima Akter'])
        ->and(array_keys($people->sole()))->toBe(['id', 'name', 'code']);
    expect($this->asToken($this->hr)->getJson(($this->api)('people'))->json('data'))->toHaveCount(2);
    // Someone who may only ask for their own leave sees nobody's list.
    $this->asToken($this->worker)->getJson(($this->api)('people'))->assertForbidden();
});

it('shows a portal employee their own leave only', function () {
    toggles()->enable($this->w->g1, 'client_portal', 'Test setup');
    ($this->ask)(['from_date' => '2026-10-20', 'to_date' => '2026-10-20'])->assertCreated();
    $member = User::factory()->create(['email_verified_at' => now()]);
    app(AddMember::class)->handle($this->w->c1, $member, MembershipType::Portal, AccessScope::Own);
    $membership = OrganizationMembership::query()->where('user_id', $member->id)->where('organization_id', $this->w->c1->id)->sole();
    (new PortalLink)->forceFill([
        'organization_id' => $this->w->c1->id, 'membership_id' => $membership->id, 'user_id' => $member->id,
        'subject_type' => 'hrm.employee', 'subject_id' => $this->rahima['id'], 'relation' => 'self', 'status' => PortalLink::ACTIVE, 'linked_via' => 'invitation',
    ])->save();
    $portal = orgToken($member, $this->w->c1);

    $mine = $this->asToken($portal)->getJson('/api/portal/leave')->assertOk()->json('data');
    expect($mine)->toHaveCount(1)
        ->and($mine[0]['employee']['name'])->toBe('Rahima Akter')
        ->and($mine[0]['requests'])->toHaveCount(1);
    $this->asToken($portal)->getJson(($this->api)('requests'))->assertForbidden();
    expect($this->asToken($this->hr)->getJson('/api/portal/leave')->json('data'))->toBe([]);
});
