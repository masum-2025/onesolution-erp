<?php

use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Rules\Enums\RuleScope;
use App\Platform\Rules\RuleCache;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\MembershipType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Attendance\Enums\DayStatus;
use Modules\Attendance\Models\AbsenceAlert;
use Modules\Attendance\Services\AbsenceStreaks;
use Modules\Attendance\Services\AttendanceSummary;
use Modules\Payroll\Services\PayCalculator;

/*
 * POL-1: leaving before the shift ends (beyond its grace), HR told once
 * about people absent many working days in a row, and pay cut by counting
 * late or early days. The old payroll.late_deduction moves to late_policy.
 * Company C1 is in Asia/Dhaka (UTC+6), weekends Friday and Saturday.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'attendance', 'Test setup');
    }
    trustedOrgRule($this->w->c1, 'attendance.weekend_days', ['fri', 'sat']);
    $this->hrUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'hrm.manage', 'attendance.view', 'attendance.manage'], 'HR'));
    $this->hr = orgToken($this->hrUser, $this->w->c1);

    $this->api = fn (string $path) => '/api/organizations/'.$this->w->c1->id."/attendance/{$path}";
    $hire = fn (string $name) => hireVia($this, $this->w->token, $this->w->b1, ['full_name' => $name, 'joined_on' => '2026-10-01'])->assertCreated()->json('data');
    $this->rahima = $hire('Rahima Akter');
    $this->karim = $hire('Karim Uddin');
    // Day shift 09:00-17:00 with an hour's break.
    $shift = $this->asToken($this->hr)->postJson(($this->api)('shifts'), ['code' => 'DAY', 'name' => ['en' => 'Day'], 'start_minute' => 540, 'end_minute' => 1020, 'break_minutes' => 60])->assertCreated()->json('data');
    $this->asToken($this->hr)->postJson(($this->api)('rosters'), ['employee_ids' => [$this->rahima['id'], $this->karim['id']], 'shift_id' => $shift['id'], 'from' => '2026-10-01'])->assertCreated();

    $this->punch = fn (array $employee, string $at) => $this->asToken($this->hr)
        ->postJson(($this->api)('punches'), ['employee_id' => $employee['id'], 'at' => $at, 'note' => 'From the register'])->assertCreated();
    $this->day = fn (array $employee, string $date) => $this->asToken($this->hr)
        ->getJson(($this->api)("days?from={$date}&to={$date}&employee_id={$employee['id']}"))->assertOk()->json('data.0');
});

it('counts leaving early only beyond the grace, and gives Payroll the days', function () {
    ($this->punch)($this->rahima, '2026-10-12T09:00');
    ($this->punch)($this->rahima, '2026-10-12T16:30'); // 30 minutes early
    ($this->punch)($this->rahima, '2026-10-13T09:00');
    ($this->punch)($this->rahima, '2026-10-13T16:55'); // within the 10-minute grace
    ($this->punch)($this->rahima, '2026-10-14T09:00');
    ($this->punch)($this->rahima, '2026-10-14T17:30'); // stayed late

    expect(($this->day)($this->rahima, '2026-10-12'))->toMatchArray(['status' => 'present', 'early_minutes' => 30])
        ->and(($this->day)($this->rahima, '2026-10-13')['early_minutes'])->toBe(0)
        ->and(($this->day)($this->rahima, '2026-10-14')['early_minutes'])->toBe(0);

    // A wider grace at the company: 30 minutes early is now allowed.
    trustedOrgRule($this->w->c1, 'attendance.early_leave_grace_minutes', 30);
    expect(($this->day)($this->rahima, '2026-10-12')['early_minutes'])->toBe(0);
    trustedOrgRule($this->w->c1, 'attendance.early_leave_grace_minutes', 10);

    $summary = app(AttendanceSummary::class)->forPeriod($this->w->c1, [$this->rahima['id']], CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-14'))[$this->rahima['id']];
    expect($summary)->toMatchArray(['early_days' => 1, 'early_minutes' => 30]);
});

it('works out a streak of absences, skipping days off and ending at any day at work', function () {
    $s = fn (string $date) => $date;
    $statuses = [
        '2026-10-14' => DayStatus::Absent, '2026-10-13' => DayStatus::Absent, '2026-10-12' => DayStatus::Absent,
        '2026-10-11' => DayStatus::Holiday, '2026-10-10' => DayStatus::Weekend, '2026-10-09' => DayStatus::Absent,
        '2026-10-08' => DayStatus::Late, '2026-10-07' => DayStatus::Absent,
    ];
    expect(AbsenceStreaks::streak($statuses, CarbonImmutable::parse('2026-10-14'), 30))->toBe(['days' => 4, 'from' => $s('2026-10-09')])
        // A day never worked out ends the streak too.
        ->and(AbsenceStreaks::streak(['2026-10-14' => DayStatus::Absent], CarbonImmutable::parse('2026-10-14'), 30))->toBe(['days' => 1, 'from' => '2026-10-14'])
        ->and(AbsenceStreaks::streak([], CarbonImmutable::parse('2026-10-14'), 30))->toBe(['days' => 0, 'from' => null]);
});

it('tells HR once about someone absent many working days in a row', function () {
    // Keep the messages queued: a sent message's values are wiped after delivery.
    Queue::fake();
    trustedOrgRule($this->w->c1, 'attendance.absent_alert_days', 5);
    // Rahima came in on the 13th; Karim never came (Oct 1-14: 10 working days).
    ($this->punch)($this->rahima, '2026-10-13T09:00');
    ($this->punch)($this->rahima, '2026-10-13T17:00');
    Artisan::call('attendance:close-days');
    $this->asToken($this->hr)->getJson(($this->api)('days?from=2026-10-01&to=2026-10-14'))->assertOk();

    Artisan::call('attendance:absence-alerts');

    $alert = AbsenceAlert::query()->withoutGlobalScopes()->sole();
    expect($alert->employee_id)->toBe($this->karim['id'])
        ->and($alert->streak_from->toDateString())->toBe('2026-10-01')
        ->and($alert->days)->toBe(10);
    $deliveries = NotificationDelivery::query()->where('notification_key', 'attendance.absent_streak')->get();
    expect($deliveries->pluck('user_id')->all())->toContain($this->hrUser->id)
        ->and($deliveries->first()->data['people'])->toContain('Karim Uddin')
        ->and($deliveries->first()->data['people'])->not->toContain('Rahima');

    // The same streak is never reported twice.
    $sent = NotificationDelivery::query()->where('notification_key', 'attendance.absent_streak')->count();
    Artisan::call('attendance:absence-alerts');
    expect(NotificationDelivery::query()->where('notification_key', 'attendance.absent_streak')->count())->toBe($sent);

    // In the bell of HR at the company, not of HR at the sister company.
    $this->asToken($this->hr)->getJson('/api/attention')->assertOk()
        ->assertJsonFragment(['key' => 'attendance.absent_in_a_row', 'count' => 1]);
    $sisterHr = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['attendance.view', 'attendance.manage'], 'HR')), $this->w->c2);
    expect(collect($this->asToken($sisterHr)->getJson('/api/attention')->json('data'))->pluck('key'))->not->toContain('attendance.absent_in_a_row');
});

it('sends nothing when the alert is off, or Attendance is off', function () {
    Queue::fake();
    $this->asToken($this->hr)->getJson(($this->api)('days?from=2026-10-01&to=2026-10-14'))->assertOk();

    trustedOrgRule($this->w->c1, 'attendance.absent_alert_days', 0);
    Artisan::call('attendance:absence-alerts');
    expect(AbsenceAlert::query()->withoutGlobalScopes()->count())->toBe(0);

    trustedOrgRule($this->w->c1, 'attendance.absent_alert_days', 5);
    toggles()->disable($this->w->g1, 'attendance', 'Stop attendance');
    Artisan::call('attendance:absence-alerts');
    expect(AbsenceAlert::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(NotificationDelivery::query()->where('notification_key', 'attendance.absent_streak')->count())->toBe(0);
});

it('cuts pay by counting late or early days', function () {
    // 7 late days at 3 per cut = 2 cuts of a day; a 30-day month of 30,000.00.
    expect(PayCalculator::countCut(3000000, 30, 7, 3, 2))->toBe(200000)
        ->and(PayCalculator::countCut(3000000, 30, 7, 3, 1))->toBe(100000)
        ->and(PayCalculator::countCut(3000000, 30, 2, 3, 2))->toBe(0);

    $facts = ['basic' => 3000000, 'items' => [], 'period_days' => 30, 'employed_days' => 30, 'absent_days' => 0, 'half_days' => 0,
        'overtime_minutes' => 0, 'late_minutes' => 95, 'late_days' => 7, 'early_days' => 4, 'adjustments' => []];
    $rules = ['deduct_absence' => true, 'overtime_multiplier_bp' => 0, 'overtime_base' => 'basic', 'monthly_hours' => 208, 'tax_slabs' => []];
    $lines = fn (array $more) => collect(PayCalculator::slip($facts, [...$rules, ...$more])['lines'])->pluck('amount', 'code')->all();

    expect($lines(['late_policy' => 'none']))->not->toHaveKey('LATE')
        ->and($lines(['late_policy' => 'per_minute']))->toMatchArray(['LATE' => 22837])
        ->and($lines(['late_policy' => 'count', 'late_per_cut' => 3, 'late_cut_halves' => 2]))->toMatchArray(['LATE' => 200000])
        ->and($lines(['late_policy' => 'none', 'early_policy' => 'count', 'early_per_cut' => 3, 'early_cut_halves' => 1]))->toMatchArray(['EARLY_LEAVE' => 50000]);
});

it('moves a company\'s per-minute late cut to the new late policy', function () {
    $migration = require base_path('database/migrations/2026_11_01_120000_move_payroll_late_deduction_to_late_policy.php');
    // A value stored for the old rule before POL-1 (written straight into the table).
    DB::table('rule_values')->insert([
        'id' => (string) Str::ulid(), 'rule_key' => 'payroll.late_deduction', 'scope_type' => RuleScope::Company->value, 'scope_id' => $this->w->c1->id,
        'country_code' => null, 'mode' => 'set', 'value' => json_encode(true), 'version' => 1, 'status' => 'active', 'reason' => 'Before POL-1',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $policyAtBranch = function () {
        app(RuleCache::class)->flushTree($this->w->g1->id);

        return app(RuleResolver::class)->get('payroll.late_policy', app(RuleContextFactory::class)->forOrganization($this->w->b1->fresh()));
    };
    expect($policyAtBranch())->toBe('none');

    $migration->up();
    expect($policyAtBranch())->toBe('per_minute')
        ->and(DB::table('rule_value_history')->where('rule_key', 'payroll.late_policy')->count())->toBe(1);

    $migration->down();
    expect(DB::table('rule_values')->where('rule_key', 'payroll.late_policy')->count())->toBe(0)
        ->and($policyAtBranch())->toBe('none');
});

it('keeps the days to the company: a sister company\'s owner cannot read them', function () {
    ($this->punch)($this->rahima, '2026-10-12T09:00');
    ($this->punch)($this->rahima, '2026-10-12T16:30');
    $sister = orgToken(createMember($this->w->c2, MembershipType::Owner), $this->w->c2);
    expect($this->asToken($sister)->getJson('/api/organizations/'.$this->w->c1->id.'/attendance/days?from=2026-10-12&to=2026-10-12')->status())->toBeIn([403, 404]);
});
