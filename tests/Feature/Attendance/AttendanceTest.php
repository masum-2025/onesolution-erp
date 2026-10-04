<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Modules\Attendance\Services\AttendanceSummary;

/*
 * ATT-1: shifts, holidays and rosters; employees checking in through their
 * linked login; days worked out (late, half day, absent, weekend, holiday,
 * night shifts, over time); corrections decided by someone else; the
 * summary Payroll reads. Company C1 is in Asia/Dhaka (UTC+6).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'attendance', 'Test setup');
    }
    trustedOrgRule($this->w->c1, 'attendance.weekend_days', ['fri', 'sat']);

    $this->hr = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'hrm.manage', 'attendance.view', 'attendance.manage'], 'HR')), $this->w->c1);
    $this->lead = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['attendance.view', 'attendance.manage', 'attendance.correct'], 'Lead')), $this->w->c1);
    $this->approver = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['attendance.view', 'attendance.correct'], 'Approver')), $this->w->c1);
    $this->workerUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['attendance.punch'], 'Worker'));
    $this->worker = orgToken($this->workerUser, $this->w->c1);

    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/attendance/{$path}";
    $hire = fn (string $name, $unit) => hireVia($this, $this->w->token, $unit, ['full_name' => $name, 'joined_on' => '2026-10-01'])->assertCreated()->json('data');
    $this->rahima = $hire('Rahima Akter', $this->w->b1);
    $this->karim = $hire('Karim Night', $this->w->b1);
    $this->asToken($this->hr)->putJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$this->rahima['id']}/login", ['base_version' => $this->rahima['version'], 'user_id' => $this->workerUser->id])->assertOk();

    $shift = fn (array $data) => $this->asToken($this->hr)->postJson(($this->api)('shifts'), $data)->assertCreated()->json('data');
    $this->day = $shift(['code' => 'DAY', 'name' => ['en' => 'Day', 'bn' => 'দিন'], 'start_minute' => 540, 'end_minute' => 1020, 'break_minutes' => 60]);
    $this->night = $shift(['code' => 'NIGHT', 'name' => ['en' => 'Night'], 'start_minute' => 1320, 'end_minute' => 360, 'break_minutes' => 30]);
    $this->asToken($this->hr)->postJson(($this->api)('rosters'), ['employee_ids' => [$this->rahima['id']], 'shift_id' => $this->day['id'], 'from' => '2026-10-01'])->assertCreated();
    $this->asToken($this->hr)->postJson(($this->api)('rosters'), ['employee_ids' => [$this->karim['id']], 'shift_id' => $this->night['id'], 'from' => '2026-10-01'])->assertCreated();

    // HR writes a punch at a local time.
    $this->punch = fn (array $employee, string $at, ?string $token = null) => $this->asToken($token ?? $this->hr)
        ->postJson(($this->api)('punches'), ['employee_id' => $employee['id'], 'at' => $at, 'note' => 'From the register']);
    $this->days = fn (string $from, string $to, ?array $employee = null) => collect($this->asToken($this->hr)->getJson(($this->api)("days?from={$from}&to={$to}".($employee ? "&employee_id={$employee['id']}" : '')))->assertOk()->json('data'));
});

it('lets a linked employee check in and out, on time and with over time', function () {
    expect($this->day)->toMatchArray(['overnight' => false, 'expected_minutes' => 420])->and($this->night['overnight'])->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-10-15 03:05:00', 'UTC')); // 09:05 in Dhaka
    $in = $this->asToken($this->worker)->postJson(($this->api)('me/punch'), ['op_id' => 'tap-1'])->assertCreated()->json('data');
    expect($in['day'])->toMatchArray(['status' => 'present', 'late_minutes' => 5, 'last_out_at' => null]);
    // A second tap, or the same op_id again, is the same punch.
    expect($this->asToken($this->worker)->postJson(($this->api)('me/punch'))->json('data.punch.id'))->toBe($in['punch']['id'])
        ->and($this->asToken($this->worker)->postJson(($this->api)('me/punch'), ['op_id' => 'tap-1'])->json('data.punch.id'))->toBe($in['punch']['id']);

    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:30:00', 'UTC')); // 18:30
    $out = $this->asToken($this->worker)->postJson(($this->api)('me/punch'))->assertCreated()->json('data.day');
    expect($out)->toMatchArray(['status' => 'present', 'worked_minutes' => 505, 'overtime_minutes' => 85, 'last_out_at' => '2026-10-15T12:30:00+00:00']);

    $me = $this->asToken($this->worker)->getJson(($this->api)('me'))->assertOk()->json('data');
    expect($me['employee']['name'])->toBe('Rahima Akter')->and($me['punches'])->toHaveCount(2)->and($me['can_punch'])->toBeTrue()
        ->and($me['timezone'])->toBe('Asia/Dhaka');
});

it('works out late, half days, absences, weekends and holidays', function () {
    $this->asToken($this->hr)->postJson(($this->api)('holidays'), ['on' => '2026-10-08', 'name' => ['en' => 'Durga Puja', 'bn' => 'দুর্গাপূজা']])->assertCreated();
    ($this->punch)($this->rahima, '2026-10-13T09:20')->assertCreated();
    ($this->punch)($this->rahima, '2026-10-13T17:00')->assertCreated();
    ($this->punch)($this->rahima, '2026-10-14T13:30')->assertCreated();
    ($this->punch)($this->rahima, '2026-10-14T13:30', $this->worker)->assertForbidden();
    ($this->punch)($this->rahima, '2026-10-16T09:00')->assertUnprocessable()->assertJsonValidationErrors('at');

    $days = ($this->days)('2026-10-08', '2026-10-15', $this->rahima)->keyBy('work_date');
    expect($days->map(fn ($day) => $day['status'])->all())->toBe([
        '2026-10-08' => 'holiday', '2026-10-09' => 'weekend', '2026-10-10' => 'weekend', '2026-10-11' => 'absent',
        '2026-10-12' => 'absent', '2026-10-13' => 'late', '2026-10-14' => 'incomplete', '2026-10-15' => 'pending',
    ])->and($days['2026-10-13'])->toMatchArray(['late_minutes' => 20, 'worked_minutes' => 400, 'overtime_minutes' => 0]);

    // Checked in at 13:30 and still at work: half a day (more than 240 minutes late).
    $this->travelTo(CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC'));
    expect(($this->days)('2026-10-14', '2026-10-14', $this->rahima)->first())->toMatchArray(['status' => 'half_day', 'late_minutes' => 270]);

    // Nothing is kept before joining; the team view lists everyone at the branch.
    expect(($this->days)('2026-09-29', '2026-10-01', $this->rahima)->pluck('work_date')->all())->toBe(['2026-10-01'])
        ->and(($this->days)('2026-10-13', '2026-10-13')->pluck('employee_name')->all())->toBe(['Karim Night', 'Rahima Akter']);
});

it('keeps a night shift on the day it started, and voids a wrong punch', function () {
    ($this->punch)($this->karim, '2026-10-13T22:02')->assertCreated();
    $out = ($this->punch)($this->karim, '2026-10-14T06:40')->assertCreated()->json('data');

    $day = ($this->days)('2026-10-13', '2026-10-13', $this->karim)->first();
    expect($day)->toMatchArray(['status' => 'present', 'worked_minutes' => 488, 'overtime_minutes' => 38, 'first_in_at' => '2026-10-13T16:02:00+00:00']);

    $this->asToken($this->hr)->postJson(($this->api)("punches/{$out['id']}/void"))->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->asToken($this->hr)->postJson(($this->api)("punches/{$out['id']}/void"), ['reason' => 'Typed the wrong time'])->assertOk()->assertJsonPath('data.voided', true);
    $this->asToken($this->hr)->postJson(($this->api)("punches/{$out['id']}/void"), ['reason' => 'Twice'])->assertConflict()->assertJsonPath('code', 'punch_voided');
    expect(($this->days)('2026-10-13', '2026-10-13', $this->karim)->first())->toMatchArray(['status' => 'incomplete', 'worked_minutes' => 0])
        ->and(AuditLog::query()->where('action', 'attendance.punch_voided')->sole()->reason)->toBe('Typed the wrong time');
});

it('fixes a day only when someone else approves the correction', function () {
    $ask = fn (string $token, array $data, string $path = 'me/corrections') => $this->asToken($token)->postJson(($this->api)($path), $data);
    $ask($this->worker, ['work_date' => '2026-09-01', 'in_at' => '2026-09-01T09:00', 'reason' => 'Forgot to punch'])->assertUnprocessable()->assertJsonValidationErrors('work_date');
    $ask($this->worker, ['work_date' => '2026-10-12', 'in_at' => '2026-10-12T17:00', 'out_at' => '2026-10-12T09:00', 'reason' => 'Forgot to punch'])->assertUnprocessable()->assertJsonValidationErrors('out_at');
    $mine = $ask($this->worker, ['work_date' => '2026-10-12', 'in_at' => '2026-10-12T09:00', 'out_at' => '2026-10-12T17:00', 'reason' => 'Phone was dead'])
        ->assertCreated()->json('data');
    expect($mine)->toMatchArray(['status' => 'pending', 'mine' => true]);

    $step = fn (string $token, array $correction, string $name, array $data = []) => $this->asToken($token)
        ->postJson(($this->api)("corrections/{$correction['id']}/{$name}"), ['base_version' => $correction['version'], ...$data]);
    $step($this->worker, $mine, 'approve')->assertForbidden();
    $step($this->hr, $mine, 'approve')->assertForbidden();
    $list = collect($this->asToken($this->approver)->getJson(($this->api)('corrections?status=pending'))->assertOk()->json('data'));
    expect($list->sole())->toMatchArray(['employee_name' => 'Rahima Akter', 'can_decide' => true]);
    $step($this->approver, $mine, 'approve')->assertOk()->assertJsonPath('data.status', 'approved');
    $step($this->approver, [...$mine, 'version' => 2], 'approve')->assertConflict()->assertJsonPath('code', 'correction_decided');
    expect(($this->days)('2026-10-12', '2026-10-12', $this->rahima)->first())->toMatchArray(['status' => 'present', 'worked_minutes' => 420]);

    // Asked by the lead for Karim: the lead cannot decide it; a note goes with a rejection.
    $theirs = $ask($this->lead, ['employee_id' => $this->karim['id'], 'work_date' => '2026-10-13', 'in_at' => '2026-10-13T22:00', 'reason' => 'Register shows him'], 'corrections')->assertCreated()->json('data');
    $step($this->lead, $theirs, 'approve')->assertForbidden()->assertJsonPath('code', 'own_correction');
    $step($this->approver, $theirs, 'reject', ['note' => 'No signature'])->assertOk()->assertJsonPath('data.status', 'rejected');
    expect(AuditLog::query()->where('action', 'attendance.correction_rejected')->sole()->reason)->toBe('No signature');
});

it('gives Payroll a summary of a period', function () {
    ($this->punch)($this->rahima, '2026-10-13T09:20')->assertCreated();
    ($this->punch)($this->rahima, '2026-10-13T19:00')->assertCreated();
    ($this->punch)($this->rahima, '2026-10-14T09:00')->assertCreated();
    ($this->punch)($this->rahima, '2026-10-14T17:00')->assertCreated();

    $summary = app(AttendanceSummary::class)->forPeriod($this->w->c1, [$this->rahima['id'], 'unknown'], CarbonImmutable::parse('2026-10-08'), CarbonImmutable::parse('2026-10-14'));
    expect(array_keys($summary))->toBe([$this->rahima['id']])
        ->and($summary[$this->rahima['id']])->toMatchArray(['attended_days' => 2, 'worked_minutes' => 940, 'late_minutes' => 20, 'overtime_minutes' => 100])
        ->and($summary[$this->rahima['id']]['days'])->toMatchArray(['present' => 1, 'late' => 1, 'absent' => 3, 'weekend' => 2]);
});

it('keeps attendance to its company, its units and the right people', function () {
    // Not linked, or self check-in off, or a location check this screen cannot do.
    $other = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['attendance.punch'], 'Other')), $this->w->c1);
    $this->asToken($other)->postJson(($this->api)('me/punch'))->assertConflict()->assertJsonPath('code', 'not_linked');
    trustedOrgRule($this->w->c1, 'attendance.self_punch', false);
    $this->asToken($this->worker)->postJson(($this->api)('me/punch'))->assertForbidden()->assertJsonPath('code', 'self_punch_off');
    trustedOrgRule($this->w->c1, 'attendance.self_punch', true);
    trustedOrgRule($this->w->c1, 'attendance.geo_fence_required', true);
    $this->asToken($this->worker)->postJson(($this->api)('me/punch'))->assertConflict()->assertJsonPath('code', 'location_needed');

    // A worker sees only their own; HR of the department below sees nobody of the branch.
    $this->asToken($this->worker)->getJson(($this->api)('days?from=2026-10-13&to=2026-10-13'))->assertForbidden();
    $deptHr = orgToken(staffWithRoles($this->w->d1, makeRole($this->w->d1, ['attendance.view', 'attendance.manage'], 'Dept HR')), $this->w->d1);
    $this->asToken($deptHr)->getJson(($this->api)('days?from=2026-10-13&to=2026-10-13', $this->w->d1))->assertOk()->assertJsonPath('data', []);
    $this->asToken($deptHr)->getJson(($this->api)("days?from=2026-10-13&to=2026-10-13&employee_id={$this->rahima['id']}", $this->w->d1))->assertNotFound();
    ($this->punch)($this->rahima, '2026-10-13T09:00', $deptHr)->assertForbidden();

    // Another company: nothing.
    $c2Hr = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['attendance.view', 'attendance.manage'], 'C2 HR')), $this->w->c2);
    $this->asToken($c2Hr)->getJson(($this->api)("days?from=2026-10-13&to=2026-10-13&employee_id={$this->rahima['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($c2Hr)->getJson(($this->api)('shifts', $this->w->c2))->assertOk()->assertJsonPath('data', []);
    $this->asToken($c2Hr)->patchJson(($this->api)("shifts/{$this->day['id']}", $this->w->c2), ['base_version' => 1, 'break_minutes' => 0])->assertNotFound();

    // Module off: closed, data kept.
    toggles()->disable($this->w->g1, 'attendance', 'Test setup', confirm: true);
    $this->asToken($this->hr)->getJson(($this->api)('shifts'))->assertForbidden();
    toggles()->enable($this->w->g1, 'attendance', 'Test setup');
    $this->asToken($this->hr)->getJson(($this->api)('shifts'))->assertOk()->assertJsonCount(2, 'data');
});

it('shows a portal employee their own days only', function () {
    toggles()->enable($this->w->g1, 'client_portal', 'Test setup');
    ($this->punch)($this->karim, '2026-10-13T22:00')->assertCreated();
    $member = User::factory()->create(['email_verified_at' => now()]);
    app(AddMember::class)->handle($this->w->c1, $member, MembershipType::Portal, AccessScope::Own);
    $membership = OrganizationMembership::query()->where('user_id', $member->id)->where('organization_id', $this->w->c1->id)->sole();
    (new PortalLink)->forceFill([
        'organization_id' => $this->w->c1->id, 'membership_id' => $membership->id, 'user_id' => $member->id,
        'subject_type' => 'hrm.employee', 'subject_id' => $this->karim['id'], 'relation' => 'self', 'status' => PortalLink::ACTIVE, 'linked_via' => 'invitation',
    ])->save();
    $portal = orgToken($member, $this->w->c1);

    $days = collect($this->asToken($portal)->getJson('/api/portal/attendance/days')->assertOk()->json('data'));
    expect($days->pluck('employee_name')->unique()->values()->all())->toBe(['Karim Night'])
        ->and($days->firstWhere('work_date', '2026-10-13')['status'])->toBe('incomplete');
    $this->asToken($portal)->getJson(($this->api)('days?from=2026-10-13&to=2026-10-13'))->assertForbidden();
    expect($this->asToken($this->hr)->getJson('/api/portal/attendance/days')->json('data'))->toBe([]);
});
