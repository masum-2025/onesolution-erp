<?php

use App\Platform\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\EducationExams\Contracts\StudentAttendance;

/*
 * STA-1: students' attendance. Once a day by the class teacher, or each
 * period by the subject's teacher (rule student_attendance.by_period);
 * saved whole, once per op id, with its version; a teacher only for their
 * own section or subject and only the rule's days back; days counted from
 * sheets (by period: the rule's share of periods), holidays and weekly
 * days off, the month's register (CSV too), report cards' days attended;
 * permission, module off and other companies.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2] as $group) {
        // A plan with every module (a new module is in no listed plan until someone adds it).
        setPlan($group, 'business');
        foreach (['education', 'hrm', 'education_exams', 'student_attendance'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/education/{$path}";
    $this->sta = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/student-attendance/{$path}";
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);

    $this->school = eduSchool($this);
    $this->subjects = collect(($this->as)()->getJson(($this->api)('structure/subjects'))->json('data'))->keyBy('code');
    $curriculum = ($this->as)()->postJson(($this->api)('structure/curricula'), ['program_id' => $this->school->program['id'], 'name' => '2026', 'effective_from_year' => 2026, 'status' => 'active'])->assertCreated()->json('data');
    foreach (['BAN1', 'MATH'] as $code) {
        ($this->as)()->postJson(($this->api)('structure/curriculum_items'), ['curriculum_id' => $curriculum['id'], 'level_id' => $this->school->class6['id'], 'subject_id' => $this->subjects[$code]['id'], 'kind' => 'compulsory'])->assertCreated();
    }
    $this->rahim = newStudent($this, $this->school, ['name' => 'Rahim', 'guardians' => []])->assertCreated()->json('data');
    $this->karim = newStudent($this, $this->school, ['name' => 'Karim', 'guardians' => []])->assertCreated()->json('data');
    $this->section = $this->school->section['id'];

    // A teacher (HRM employee with a login and the school template's permissions): class teacher of section A, or a subject's teacher there.
    $this->teacher = function (string $name, bool $classTeacher = false, ?string $subject = null) {
        $user = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view', 'student_attendance.view', 'student_attendance.take'], "Teacher {$name}"));
        $employee = hireVia($this, $this->token, $this->w->c1, ['full_name' => $name, 'joined_on' => '2026-01-01'])->assertCreated()->json('data.id');
        DB::table('hrm_employees')->where('id', $employee)->update(['user_id' => $user->id]);
        if ($classTeacher) {
            $section = ($this->as)()->getJson(($this->api)("structure/sections?session_id={$this->school->session['id']}"))->json('data.0');
            ($this->as)()->patchJson(($this->api)("structure/sections/{$this->section}"), ['base_version' => $section['version'], 'class_teacher_id' => $employee])->assertOk();
        }
        if ($subject !== null) {
            ($this->as)()->postJson(($this->api)('structure/subject_teachers'), ['section_id' => $this->section, 'subject_id' => $this->subjects[$subject]['id'], 'teacher_id' => $employee])->assertCreated();
        }

        return (object) ['user' => $user, 'token' => orgToken($user, $this->w->c1), 'employee' => $employee];
    };
    $this->sheet = fn (string $date, array $body, ?string $token = null) => ($this->as)($token)->putJson(($this->sta)("sheets/{$this->section}/{$date}"), $body);
    $this->mark = fn (array $student, string $status, ?string $note = null) => ['student_id' => $student['id'], 'status' => $status, 'note' => $note];
});

it('lets the class teacher take the day once, change it with its version, and audits the change', function () {
    $rina = ($this->teacher)('Rina Akter', classTeacher: true);

    $open = ($this->as)($rina->token)->getJson(($this->sta)("sheets/{$this->section}/2026-03-10"))->assertOk()->json('data');
    expect($open['may_take'])->toBeTrue()->and($open['sheet'])->toBeNull()->and($open['by_period'])->toBeFalse()
        ->and(collect($open['students'])->pluck('status')->unique()->all())->toBe(['present']);

    $taken = ($this->sheet)('2026-03-10', ['marks' => [($this->mark)($this->karim, 'absent', 'Fever')], 'op_id' => 'tap-1'], $rina->token)->assertCreated()->json('data');
    expect($taken['sheet'])->toMatchArray(['slot' => 'day', 'version' => 1, 'counts' => ['present' => 1, 'absent' => 1, 'late' => 0, 'leave' => 0]])
        ->and(collect($taken['students'])->firstWhere('id', $this->karim['id']))->toMatchArray(['status' => 'absent', 'note' => 'Fever']);
    // The same sheet again (a resend from a phone) is the same sheet.
    ($this->sheet)('2026-03-10', ['marks' => [], 'op_id' => 'tap-1'], $rina->token)->assertOk()->assertJsonPath('data.sheet.id', $taken['sheet']['id']);
    // Saved meanwhile by someone else: without its version, refused.
    ($this->sheet)('2026-03-10', ['marks' => []], $rina->token)->assertStatus(409)->assertJsonPath('code', 'version_conflict')->assertJsonPath('current.version', 1);

    // Karim came after all, late; Rahim left out stays present.
    $changed = ($this->sheet)('2026-03-10', ['marks' => [($this->mark)($this->karim, 'late')], 'base_version' => 1], $rina->token)->assertOk()->json('data');
    expect($changed['sheet'])->toMatchArray(['version' => 2, 'counts' => ['present' => 1, 'absent' => 0, 'late' => 1, 'leave' => 0]]);
    $audit = AuditLog::query()->where('action', 'student_attendance.changed')->latest('id')->first();
    expect($audit->old_values['marks'])->toBe([$this->karim['id'] => 'absent'])->and($audit->new_values['marks'])->toBe([$this->karim['id'] => 'late']);

    $today = ($this->as)()->getJson(($this->sta)('sheets?date=2026-03-10'))->assertOk()->json('data');
    expect(collect($today)->firstWhere('section_id', $this->section)['sheets'][0]['counts']['late'])->toBe(1);
});

it('keeps a teacher to their own section, today and the rule\'s days back; checks every student and day', function () {
    $rina = ($this->teacher)('Rina Akter', classTeacher: true);
    $other = ($this->teacher)('Selim Reza', subject: 'MATH');

    // A subject teacher does not take the day's attendance; the day sheet says so before they try.
    ($this->as)($other->token)->getJson(($this->sta)("sheets/{$this->section}/2026-03-10"))->assertOk()->assertJsonPath('data.may_take', false);
    ($this->sheet)('2026-03-10', ['marks' => []], $other->token)->assertForbidden()->assertJsonPath('code', 'not_your_section');

    ($this->sheet)('2026-03-11', ['marks' => []], $rina->token)->assertUnprocessable()->assertJsonPath('code', 'future_day');
    ($this->sheet)('2026-03-08', ['marks' => []], $rina->token)->assertCreated();
    ($this->sheet)('2026-03-07', ['marks' => []], $rina->token)->assertUnprocessable()->assertJsonPath('code', 'outside_window');
    orgRule($this->w->c1, 'student_attendance.edit_days', 5);
    ($this->sheet)('2026-03-07', ['marks' => []], $rina->token)->assertCreated();

    // Whoever manages goes back further (and it is audited), never outside the session.
    ($this->sheet)('2026-02-02', ['marks' => [($this->mark)($this->rahim, 'leave')]])->assertCreated();
    // Every sheet first taken for an earlier day: the teacher's two and this one.
    expect(AuditLog::query()->where('action', 'student_attendance.taken_late')->count())->toBe(3);
    ($this->sheet)('2025-12-31', ['marks' => []])->assertUnprocessable()->assertJsonPath('code', 'outside_session');

    // Nobody outside the section, no unknown fields, no period while it is taken once a day.
    ($this->sheet)('2026-03-10', ['marks' => [['student_id' => str_repeat('A', 26), 'status' => 'absent']]])->assertUnprocessable()->assertJsonPath('code', 'student_not_in_section');
    ($this->sheet)('2026-03-10', ['marks' => [['student_id' => $this->rahim['id'], 'status' => 'sleeping']]])->assertUnprocessable()->assertJsonValidationErrors('marks.0.status');
    ($this->sheet)('2026-03-10', ['marks' => [], 'organization_id' => 'x'])->assertUnprocessable();
    ($this->sheet)('2026-03-10', ['marks' => [], 'period_id' => str_repeat('B', 26)])->assertUnprocessable()->assertJsonPath('code', 'period_not_used');
    ($this->as)()->getJson(($this->sta)("sheets/{$this->section}/2026-02-30"))->assertUnprocessable()->assertJsonValidationErrors('date');
});

it('takes attendance in each period when the rule is on, by the subject\'s teacher, and counts the day by the share of periods', function () {
    orgRule($this->w->c1, 'student_attendance.by_period', true);
    $p1 = ($this->as)()->postJson(($this->sta)('periods', $this->w->b1), ['number' => 1, 'name' => ['en' => '1st period', 'bn' => '১ম পিরিয়ড'], 'starts_at' => '08:00', 'ends_at' => '08:45'])->assertCreated()->json('data');
    $p2 = ($this->as)()->postJson(($this->sta)('periods', $this->w->b1), ['number' => 2, 'name' => ['en' => '2nd period'], 'starts_at' => '08:45', 'ends_at' => '09:30'])->assertCreated()->json('data');
    ($this->as)()->postJson(($this->sta)('periods', $this->w->b1), ['number' => 2, 'name' => ['en' => 'Again']])->assertUnprocessable()->assertJsonValidationErrors('number');
    ($this->as)()->postJson(($this->sta)('periods', $this->w->b1), ['number' => 3, 'name' => ['en' => 'Odd'], 'starts_at' => '10:00', 'ends_at' => '09:00'])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    $maths = ($this->teacher)('Selim Reza', subject: 'MATH');
    $rina = ($this->teacher)('Rina Akter', classTeacher: true);

    ($this->sheet)('2026-03-10', ['marks' => []], $maths->token)->assertUnprocessable()->assertJsonPath('code', 'period_needed');
    // Their only subject there is picked for them; another subject is not theirs.
    $first = ($this->sheet)('2026-03-10', ['period_id' => $p1['id'], 'marks' => [($this->mark)($this->karim, 'absent')]], $maths->token)->assertCreated()->json('data');
    expect($first['sheet'])->toMatchArray(['slot' => $p1['id'], 'period_id' => $p1['id'], 'subject_id' => $this->subjects['MATH']['id'], 'teacher_id' => $maths->employee]);
    ($this->as)($maths->token)->putJson(($this->sta)("sheets/{$this->section}/2026-03-10"), ['period_id' => $p2['id'], 'subject_id' => $this->subjects['BAN1']['id'], 'marks' => []])
        ->assertForbidden()->assertJsonPath('code', 'not_your_section');
    // The class teacher covers a period too.
    ($this->sheet)('2026-03-10', ['period_id' => $p2['id'], 'subject_id' => $this->subjects['BAN1']['id'], 'marks' => [($this->mark)($this->rahim, 'absent'), ($this->mark)($this->karim, 'absent')]], $rina->token)->assertCreated();

    // Rahim: one of two periods (half, the default share) -> present; Karim: none -> absent.
    $rahim = ($this->as)()->getJson(($this->sta)("students/{$this->rahim['id']}?month=2026-03"))->assertOk()->json('data');
    expect($rahim['days'][0])->toMatchArray(['date' => '2026-03-10', 'status' => 'present'])->and($rahim['days'][0]['periods'])->toHaveCount(2)
        ->and($rahim['totals'])->toBe(['present' => 1, 'absent' => 0, 'late' => 0, 'leave' => 0, 'days' => 1]);
    ($this->as)()->getJson(($this->sta)("students/{$this->karim['id']}?month=2026-03"))->assertJsonPath('data.days.0.status', 'absent');
    orgRule($this->w->c1, 'student_attendance.period_present_share_bp', 7500);
    ($this->as)()->getJson(($this->sta)("students/{$this->rahim['id']}?month=2026-03"))->assertJsonPath('data.days.0.status', 'absent');

    // A period attendance was taken in is switched off, not removed; a switched-off one takes no sheet.
    ($this->as)()->deleteJson(($this->sta)("periods/{$p1['id']}?base_version=1"))->assertStatus(409)->assertJsonPath('code', 'period_in_use');
    ($this->as)()->patchJson(($this->sta)("periods/{$p1['id']}"), ['is_active' => false, 'base_version' => 1])->assertOk()->assertJsonPath('data.is_active', false);
    ($this->sheet)('2026-03-09', ['period_id' => $p1['id'], 'marks' => []], $maths->token)->assertUnprocessable()->assertJsonPath('code', 'period_inactive');
    $p3 = ($this->as)()->postJson(($this->sta)('periods', $this->w->b1), ['number' => 3, 'name' => ['en' => '3rd']])->assertCreated()->json('data');
    ($this->as)()->deleteJson(($this->sta)("periods/{$p3['id']}?base_version=1"))->assertNoContent();
    // Teachers do not set periods.
    ($this->as)($maths->token)->postJson(($this->sta)('periods', $this->w->b1), ['number' => 4, 'name' => ['en' => '4th']])->assertForbidden();
});

it('says when a day is off (weekly or a holiday) and lets only managers keep holidays', function () {
    orgRule($this->w->c1, 'student_attendance.weekend_days', ['fri', 'sat']);
    ($this->as)()->getJson(($this->sta)("sheets/{$this->section}/2026-03-06"))->assertOk()->assertJsonPath('data.day_off.weekend', true);
    ($this->as)()->getJson(($this->sta)("sheets/{$this->section}/2026-03-09"))->assertJsonPath('data.day_off', ['weekend' => false, 'holiday' => null]);

    $holiday = ($this->as)()->postJson(($this->sta)('holidays'), ['starts_on' => '2026-03-09', 'name' => ['en' => 'Sports day', 'bn' => 'ক্রীড়া দিবস']])->assertCreated()->json('data');
    expect($holiday)->toMatchArray(['unit_id' => null, 'starts_on' => '2026-03-09', 'ends_on' => '2026-03-09', 'level_ids' => null]);
    ($this->as)()->getJson(($this->sta)("sheets/{$this->section}/2026-03-09"))->assertJsonPath('data.day_off.holiday.bn', 'ক্রীড়া দিবস');
    // Another class's holiday is not this section's.
    $other = collect($this->school->setup['levels'])->first(fn ($level) => $level['id'] !== $this->school->class6['id']);
    ($this->as)()->postJson(($this->sta)('holidays'), ['starts_on' => '2026-03-08', 'name' => ['en' => 'Class 7 trip'], 'level_ids' => [$other['id']]])->assertCreated();
    ($this->as)()->getJson(($this->sta)("sheets/{$this->section}/2026-03-08"))->assertJsonPath('data.day_off.holiday', null);
    // From a campus, a holiday is that campus's.
    ($this->as)()->postJson(($this->sta)('holidays', $this->w->b1), ['starts_on' => '2026-03-05', 'name' => ['en' => 'Campus day']])->assertCreated()->assertJsonPath('data.unit_id', $this->w->b1->id);

    ($this->as)()->postJson(($this->sta)('holidays'), ['starts_on' => '2026-01-01', 'ends_on' => '2026-08-01', 'name' => ['en' => 'Too long']])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
    ($this->as)()->postJson(($this->sta)('holidays'), ['starts_on' => '2026-03-08', 'name' => ['en' => 'X'], 'level_ids' => [str_repeat('C', 26)]])->assertUnprocessable()->assertJsonValidationErrors('level_ids');
    $rina = ($this->teacher)('Rina Akter', classTeacher: true);
    ($this->as)($rina->token)->postJson(($this->sta)('holidays'), ['starts_on' => '2026-03-08', 'name' => ['en' => 'Mine']])->assertForbidden();
    ($this->as)($rina->token)->getJson(($this->sta)('holidays?from=2026-03-01&to=2026-03-31'))->assertOk()->assertJsonCount(3, 'data');

    ($this->as)()->deleteJson(($this->sta)("holidays/{$holiday['id']}"))->assertNoContent();
    expect(AuditLog::query()->whereIn('action', ['student_attendance.holiday_added', 'student_attendance.holiday_removed'])->count())->toBe(4);
});

it('shows a section\'s month as a register, also as CSV', function () {
    ($this->sheet)('2026-03-09', ['marks' => [($this->mark)($this->karim, 'absent')]])->assertCreated();
    ($this->sheet)('2026-03-10', ['marks' => [($this->mark)($this->karim, 'late')]])->assertCreated();

    $register = ($this->as)()->getJson(($this->sta)("register?section_id={$this->section}&month=2026-03"))->assertOk()->json('data');
    expect($register['dates'])->toBe(['2026-03-09', '2026-03-10']);
    $karim = collect($register['students'])->firstWhere('id', $this->karim['id']);
    expect($karim['days'])->toBe(['2026-03-09' => 'absent', '2026-03-10' => 'late'])
        ->and($karim['totals'])->toBe(['present' => 1, 'absent' => 1, 'late' => 1, 'leave' => 0, 'days' => 2]);
    // Late not counted as present when the institution says so.
    orgRule($this->w->c1, 'student_attendance.late_counts_present', false);
    $after = collect(($this->as)()->getJson(($this->sta)("register?section_id={$this->section}&month=2026-03"))->json('data.students'))->firstWhere('id', $this->karim['id']);
    expect($after['totals']['present'])->toBe(0);

    $csv = ($this->as)()->get(($this->sta)("register?section_id={$this->section}&month=2026-03&format=csv"))->assertOk();
    expect($csv->headers->get('Content-Type'))->toContain('text/csv')
        ->and($csv->streamedContent())->toContain('Roll,Name,ID,09,10')->toContain('Karim');
    ($this->as)()->getJson(($this->sta)("register?section_id={$this->section}&month=2026-13"))->assertUnprocessable()->assertJsonValidationErrors('month');
});

it('gives report cards the days attended in the session, nothing with the module off', function () {
    ($this->sheet)('2026-03-09', ['marks' => [($this->mark)($this->karim, 'absent')]])->assertCreated();
    ($this->sheet)('2026-03-10', ['marks' => [($this->mark)($this->karim, 'leave')]])->assertCreated();
    $contract = app(StudentAttendance::class);

    expect($contract->forSession($this->w->c1, $this->school->session['id'], [$this->rahim['id'], $this->karim['id']]))->toBe([
        $this->rahim['id'] => ['present' => 2, 'days' => 2],
        $this->karim['id'] => ['present' => 0, 'days' => 2],
    ]);
    toggles()->disable($this->w->c1, 'student_attendance', 'Test');
    expect(app(StudentAttendance::class)->forSession($this->w->c1, $this->school->session['id'], [$this->rahim['id']]))->toBe([]);
});

it('needs the permission, answers 403 with the module off, and never shows another company\'s attendance', function () {
    ($this->sheet)('2026-03-10', ['marks' => []])->assertCreated();
    $nobody = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view'], 'Clerk')), $this->w->c1);
    ($this->as)($nobody)->getJson(($this->sta)('setup'))->assertForbidden();
    ($this->as)($nobody)->putJson(($this->sta)("sheets/{$this->section}/2026-03-10"), ['marks' => []])->assertForbidden();
    // A teacher of another section sees nothing of this one.
    $viewer = ($this->teacher)('Nobody Here');
    ($this->as)($viewer->token)->getJson(($this->sta)("sheets/{$this->section}/2026-03-10"))->assertNotFound();
    ($this->as)($viewer->token)->getJson(($this->sta)("students/{$this->rahim['id']}"))->assertNotFound();
    expect(($this->as)($viewer->token)->getJson(($this->sta)('setup'))->assertOk()->json('data.sections'))->toBe([]);

    $other = orgToken(createMember($this->w->c2), $this->w->c2);
    ($this->as)($other)->getJson(($this->sta)("sheets/{$this->section}/2026-03-10", $this->w->c2))->assertNotFound();
    ($this->as)($other)->putJson(($this->sta)("sheets/{$this->section}/2026-03-10", $this->w->c2), ['marks' => []])->assertNotFound();
    ($this->as)($other)->getJson(($this->sta)("register?section_id={$this->section}", $this->w->c2))->assertNotFound();
    ($this->as)($other)->getJson(($this->sta)("students/{$this->rahim['id']}", $this->w->c2))->assertNotFound();
    ($this->as)($other)->getJson(($this->sta)("sheets/{$this->section}/2026-03-10"))->assertNotFound();

    toggles()->disable($this->w->c1, 'student_attendance', 'Test');
    ($this->as)()->getJson(($this->sta)('setup'))->assertForbidden();
});
