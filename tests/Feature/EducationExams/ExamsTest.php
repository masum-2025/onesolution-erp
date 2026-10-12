<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\EducationExams\Models\Mark;

/*
 * EXM-1: exams and marks. Grading presets copied in; exams and papers
 * (parts adding up, from the curriculum); opening for marks makes a sheet
 * per paper and section; the class teacher (or the teacher named) enters
 * marks, hands them in, someone else approves or sends them back; the
 * rule turns moderation off; isolation and module off.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'education', 'Test setup');
        toggles()->enable($group, 'education_exams', 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/education/{$path}";
    $this->exams = fn (string $path = '', $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id.'/exams'.($path === '' ? '' : "/{$path}");
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);

    $this->school = eduSchool($this);
    $this->subjects = collect(($this->as)()->getJson(($this->api)('structure/subjects'))->json('data'))->keyBy('code');
    $curriculum = ($this->as)()->postJson(($this->api)('structure/curricula'), ['program_id' => $this->school->program['id'], 'name' => '2026', 'effective_from_year' => 2026, 'status' => 'active'])->assertCreated()->json('data');
    foreach (['BAN1' => 'compulsory', 'ENG1' => 'compulsory', 'MATH' => 'compulsory'] as $code => $kind) {
        ($this->as)()->postJson(($this->api)('structure/curriculum_items'), ['curriculum_id' => $curriculum['id'], 'level_id' => $this->school->class6['id'], 'subject_id' => $this->subjects[$code]['id'], 'kind' => $kind])->assertCreated();
    }
    $this->rahim = newStudent($this, $this->school, ['name' => 'Rahim', 'guardians' => []])->assertCreated()->json('data');
    $this->karim = newStudent($this, $this->school, ['name' => 'Karim', 'guardians' => []])->assertCreated()->json('data');

    $this->setup = fn () => ($this->as)()->getJson(($this->exams)('setup'))->assertOk()->json('data');
    $this->exam = function (array $extra = []) {
        $gpa5 = collect(($this->setup)()['schemes'])->firstWhere('code', 'bd_school_gpa5');

        return ($this->as)()->postJson(($this->exams)(), [
            'name' => ['en' => 'Half-yearly 2026', 'bn' => 'অর্ধবার্ষিক ২০২৬'], 'session_id' => $this->school->session['id'], 'kind' => 'term',
            'starts_on' => '2026-06-01', 'ends_on' => '2026-06-15', 'grading_scheme_id' => $gpa5['id'], ...$extra,
        ])->assertCreated()->json('data');
    };
    // A teacher linked to an HRM employee; made the class teacher of section A when asked.
    $this->teacher = function (string $name, bool $classTeacher = false) {
        toggles()->enable($this->w->g1, 'hrm', 'Test setup');
        $user = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view', 'education_exams.view', 'education_exams.enter_marks'], "Teacher {$name}"));
        $employee = hireVia($this, $this->token, $this->w->c1, ['full_name' => $name, 'joined_on' => '2026-01-01'])->assertCreated()->json('data.id');
        DB::table('hrm_employees')->where('id', $employee)->update(['user_id' => $user->id]);
        if ($classTeacher) {
            $section = ($this->as)()->getJson(($this->api)("structure/sections?session_id={$this->school->session['id']}"))->json('data.0');
            ($this->as)()->patchJson(($this->api)("structure/sections/{$this->school->section['id']}"), ['base_version' => $section['version'], 'class_teacher_id' => $employee])->assertOk();
        }

        return (object) ['user' => $user, 'token' => orgToken($user, $this->w->c1), 'employee' => $employee];
    };
});

it('copies the grading presets in once and keeps an institution\'s own scheme whole', function () {
    $schemes = collect(($this->setup)()['schemes']);
    expect($schemes->pluck('code')->all())->toBe(['bd_school_gpa5', 'bd_university_ugc', 'percentage'])
        ->and($schemes->firstWhere('code', 'bd_school_gpa5')['bands'])->toHaveCount(7)
        ->and(collect(($this->setup)()['schemes']))->toHaveCount(3);

    ($this->as)()->postJson(($this->exams)('schemes'), ['code' => 'own', 'name' => ['en' => 'Own'], 'bands' => [['min_percent_bp' => 5000, 'letter' => 'P', 'is_pass' => true]]])
        ->assertUnprocessable()->assertJsonPath('code', 'bands_incomplete');
    $own = ($this->as)()->postJson(($this->exams)('schemes'), ['code' => 'own', 'name' => ['en' => 'Own'], 'bands' => [
        ['min_percent_bp' => 5000, 'letter' => 'P', 'is_pass' => true], ['min_percent_bp' => 0, 'letter' => 'F', 'is_pass' => false],
    ]])->assertCreated()->json('data');
    expect($own['bands'][0])->toMatchArray(['min_percent_bp' => 5000, 'letter' => 'P']);
    ($this->as)()->postJson(($this->exams)('schemes'), ['code' => 'own', 'name' => ['en' => 'Again'], 'bands' => [['min_percent_bp' => 0, 'letter' => 'F', 'is_pass' => false]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);
});

it('sets papers from the curriculum or by hand, parts adding up, and opens a sheet per paper and section', function () {
    $exam = ($this->exam)();
    ($this->as)()->postJson(($this->exams)("{$exam['id']}/status"), ['status' => 'marking', 'base_version' => $exam['version']])->assertUnprocessable()->assertJsonPath('code', 'no_papers');

    $made = ($this->as)()->postJson(($this->exams)("{$exam['id']}/papers/from-curriculum"), ['level_id' => $this->school->class6['id'], 'full_marks_centi' => 10000, 'pass_marks_centi' => 3300])->assertCreated()->json('data');
    expect(collect($made)->pluck('subject.code')->sort()->values()->all())->toBe(['BAN1', 'ENG1', 'MATH'])
        ->and($made[0]['parts'])->toHaveCount(1);
    expect(($this->as)()->postJson(($this->exams)("{$exam['id']}/papers/from-curriculum"), ['level_id' => $this->school->class6['id'], 'full_marks_centi' => 10000, 'pass_marks_centi' => 3300])->json('data'))->toBe([]);

    // Creative 70 + MCQ 30 = 100; parts must add up.
    $math = collect($made)->firstWhere('subject.code', 'MATH');
    ($this->as)()->patchJson(($this->exams)("{$exam['id']}/papers/{$math['id']}"), ['parts' => [['code' => 'CQ', 'full_marks_centi' => 7000], ['code' => 'MCQ', 'full_marks_centi' => 2000]], 'base_version' => $math['version']])
        ->assertUnprocessable()->assertJsonPath('code', 'parts_mismatch');
    $math = ($this->as)()->patchJson(($this->exams)("{$exam['id']}/papers/{$math['id']}"), [
        'parts' => [['code' => 'CQ', 'name' => ['en' => 'Creative'], 'full_marks_centi' => 7000, 'pass_marks_centi' => 2300], ['code' => 'MCQ', 'full_marks_centi' => 3000, 'pass_marks_centi' => 1000]],
        'sits_on' => '2026-06-03', 'starts_at' => '10:00', 'minutes' => 180, 'room' => '101', 'base_version' => $math['version'],
    ])->assertOk()->json('data');
    expect(collect($math['parts'])->pluck('code')->all())->toBe(['CQ', 'MCQ'])->and($math['sits_on'])->toBe('2026-06-03');
    ($this->as)()->postJson(($this->exams)("{$exam['id']}/papers"), ['level_id' => $this->school->class6['id'], 'subject_id' => $this->subjects['MATH']['id'], 'full_marks_centi' => 10000])
        ->assertUnprocessable()->assertJsonPath('code', 'paper_exists');

    $open = ($this->as)()->postJson(($this->exams)("{$exam['id']}/status"), ['status' => 'marking', 'base_version' => $exam['version']])->assertOk()->json('data');
    expect($open['status'])->toBe('marking')->and($open['counts'])->toBe(['open' => 3]);
    expect(($this->as)()->getJson(($this->exams)("{$exam['id']}/sheets"))->json('data'))->toHaveCount(3);
});

it('lets the class teacher enter, hand in, and someone else approve or send back', function () {
    $exam = ($this->exam)();
    $papers = ($this->as)()->postJson(($this->exams)("{$exam['id']}/papers/from-curriculum"), ['level_id' => $this->school->class6['id'], 'full_marks_centi' => 10000, 'pass_marks_centi' => 3300])->json('data');
    ($this->as)()->postJson(($this->exams)("{$exam['id']}/status"), ['status' => 'marking', 'base_version' => $exam['version']])->assertOk();
    $classTeacher = ($this->teacher)('Class Teacher', true);
    $other = ($this->teacher)('Other Teacher');

    $sheets = ($this->as)($classTeacher->token)->getJson(($this->exams)("{$exam['id']}/sheets?mine=1"))->assertOk()->json('data');
    expect($sheets)->toHaveCount(3);
    expect(($this->as)($other->token)->getJson(($this->exams)("{$exam['id']}/sheets?mine=1"))->json('data'))->toBe([]);
    $sheet = ($this->as)($classTeacher->token)->getJson(($this->exams)("sheets/{$sheets[0]['id']}"))->assertOk()->json('data');
    $part = $sheet['parts'][0]['id'];
    expect($sheet['students'])->toHaveCount(2)->and($sheet['mine'])->toBeTrue();

    // Another teacher may not; too many marks are refused.
    ($this->as)($other->token)->putJson(($this->exams)("sheets/{$sheet['id']}/marks"), ['marks' => [['student_id' => $this->rahim['id'], 'part_id' => $part, 'marks_centi' => 8000]]])
        ->assertForbidden()->assertJsonPath('code', 'not_your_sheet');
    ($this->as)($classTeacher->token)->putJson(($this->exams)("sheets/{$sheet['id']}/marks"), ['marks' => [['student_id' => $this->rahim['id'], 'part_id' => $part, 'marks_centi' => 10100]]])
        ->assertUnprocessable()->assertJsonPath('code', 'marks_too_high');
    $saved = ($this->as)($classTeacher->token)->putJson(($this->exams)("sheets/{$sheet['id']}/marks"), ['marks' => [['student_id' => $this->rahim['id'], 'part_id' => $part, 'marks_centi' => 8250]]])->assertOk()->json('data');
    ($this->as)($classTeacher->token)->postJson(($this->exams)("sheets/{$sheet['id']}/submit"), ['base_version' => $saved['version']])
        ->assertUnprocessable()->assertJsonPath('code', 'sheet_incomplete');
    $saved = ($this->as)($classTeacher->token)->putJson(($this->exams)("sheets/{$sheet['id']}/marks"), ['marks' => [['student_id' => $this->karim['id'], 'part_id' => $part, 'absent' => true]]])->assertOk()->json('data');
    expect(collect($saved['students'])->firstWhere('id', $this->karim['id'])['marks'][$part])->toBe(['marks_centi' => null, 'absent' => true]);
    $handed = ($this->as)($classTeacher->token)->postJson(($this->exams)("sheets/{$sheet['id']}/submit"), ['base_version' => $saved['version']])->assertOk()->json('data');
    expect($handed['status'])->toBe('submitted');
    ($this->as)($classTeacher->token)->putJson(($this->exams)("sheets/{$sheet['id']}/marks"), ['marks' => [['student_id' => $this->rahim['id'], 'part_id' => $part, 'marks_centi' => 9000]]])
        ->assertStatus(409)->assertJsonPath('code', 'wrong_status');

    // Sent back with a note, handed in again, approved by someone else.
    ($this->as)()->postJson(($this->exams)("sheets/{$sheet['id']}/send-back"), ['base_version' => $handed['version']])->assertUnprocessable()->assertJsonValidationErrors(['note']);
    $back = ($this->as)()->postJson(($this->exams)("sheets/{$sheet['id']}/send-back"), ['note' => 'Check Rahim', 'base_version' => $handed['version']])->assertOk()->json('data');
    expect($back)->toMatchArray(['status' => 'returned', 'note' => 'Check Rahim']);
    $again = ($this->as)($classTeacher->token)->postJson(($this->exams)("sheets/{$sheet['id']}/submit"), ['base_version' => $back['version']])->assertOk()->json('data');
    $checker = createMember($this->w->c1);
    ($this->as)(orgToken($checker, $this->w->c1))->postJson(($this->exams)("sheets/{$sheet['id']}/approve"), ['base_version' => $again['version']])->assertOk()->assertJsonPath('data.status', 'approved');

    // Naming another teacher hands the next sheet to them (and away from the class teacher).
    $next = collect($sheets)->firstWhere('id', '!=', $sheet['id']);
    ($this->as)()->postJson(($this->exams)("sheets/{$next['id']}/assign"), ['teacher_id' => $other->employee, 'base_version' => $next['version']])->assertOk();
    expect(collect(($this->as)($other->token)->getJson(($this->exams)("{$exam['id']}/sheets?mine=1"))->json('data'))->pluck('id')->all())->toBe([$next['id']]);
    ($this->as)($classTeacher->token)->putJson(($this->exams)("sheets/{$next['id']}/marks"), ['marks' => [['student_id' => $this->rahim['id'], 'part_id' => $part, 'marks_centi' => 1]]])
        ->assertForbidden();
    expect(AuditLog::query()->where('action', 'education_exams.sheet_approved')->count())->toBe(1);
});

it('approves on handing in without moderation, and keeps marks once the exam is closed', function () {
    orgRule($this->w->c1, 'education_exams.moderation_required', false);
    $exam = ($this->exam)();
    $paper = ($this->as)()->postJson(($this->exams)("{$exam['id']}/papers"), ['level_id' => $this->school->class6['id'], 'subject_id' => $this->subjects['BAN1']['id'], 'full_marks_centi' => 5000, 'pass_marks_centi' => 1700])->assertCreated()->json('data');
    $exam = ($this->as)()->postJson(($this->exams)("{$exam['id']}/status"), ['status' => 'marking', 'base_version' => $exam['version']])->assertOk()->json('data');
    $sheet = ($this->as)()->getJson(($this->exams)("{$exam['id']}/sheets"))->json('data.0');
    $part = $paper['parts'][0]['id'];
    $saved = ($this->as)()->putJson(($this->exams)("sheets/{$sheet['id']}/marks"), ['marks' => [
        ['student_id' => $this->rahim['id'], 'part_id' => $part, 'marks_centi' => 4000], ['student_id' => $this->karim['id'], 'part_id' => $part, 'marks_centi' => 1500],
    ]])->assertOk()->json('data');
    ($this->as)()->postJson(($this->exams)("sheets/{$sheet['id']}/submit"), ['base_version' => $saved['version']])->assertOk()->assertJsonPath('data.status', 'approved');

    // A paper with marks keeps its marks; a closed exam takes none.
    ($this->as)()->patchJson(($this->exams)("{$exam['id']}/papers/{$paper['id']}"), ['full_marks_centi' => 10000, 'base_version' => $paper['version']])->assertStatus(409)->assertJsonPath('code', 'paper_has_marks');
    ($this->as)()->deleteJson(($this->exams)("{$exam['id']}/papers/{$paper['id']}"))->assertStatus(409);
    $closed = ($this->as)()->postJson(($this->exams)("{$exam['id']}/status"), ['status' => 'closed', 'base_version' => $exam['version']])->assertOk()->json('data');
    expect($closed['status'])->toBe('closed')->and(Mark::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('keeps exams to the people allowed, inside their institution, and answers 403 while exams are off', function () {
    $exam = ($this->exam)();
    $viewer = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view', 'education_exams.view']));
    $viewerToken = orgToken($viewer, $this->w->c1);
    ($this->as)($viewerToken)->getJson(($this->exams)($exam['id']))->assertOk();
    ($this->as)($viewerToken)->postJson(($this->exams)("{$exam['id']}/papers"), ['level_id' => $this->school->class6['id'], 'subject_id' => $this->subjects['BAN1']['id'], 'full_marks_centi' => 10000])->assertForbidden();

    foreach ([$this->w->c2, $this->w->c4] as $other) {
        $stranger = orgToken(createMember($other), $other);
        $this->asToken($stranger)->getJson(($this->exams)($exam['id'], $other))->assertNotFound();
        expect($this->asToken($stranger)->getJson(($this->exams)('', $other))->json('data'))->toBe([]);
    }
    toggles()->disable($this->w->g1, 'education_exams', 'Test');
    ($this->as)()->getJson(($this->exams)())->assertForbidden();
});

it('lists a teacher\'s own sheets across exams open for marks, named, and gives teachers to pick to managers only', function () {
    $exam = ($this->exam)();
    ($this->as)()->postJson(($this->exams)("{$exam['id']}/papers/from-curriculum"), ['level_id' => $this->school->class6['id'], 'full_marks_centi' => 10000, 'pass_marks_centi' => 3300])->assertCreated();
    $draft = ($this->exam)(['name' => ['en' => 'Annual 2026']]);
    ($this->as)()->postJson(($this->exams)("{$draft['id']}/papers"), ['level_id' => $this->school->class6['id'], 'subject_id' => $this->subjects['BAN1']['id'], 'full_marks_centi' => 10000])->assertCreated();
    ($this->as)()->postJson(($this->exams)("{$exam['id']}/status"), ['status' => 'marking', 'base_version' => $exam['version']])->assertOk();
    $classTeacher = ($this->teacher)('Class Teacher', true);
    $other = ($this->teacher)('Other Teacher');

    // The class teacher's three sheets of the exam open for marks (none of the draft), with what each is for.
    $mine = ($this->as)($classTeacher->token)->getJson(($this->exams)('sheets'))->assertOk()->json('data');
    expect($mine)->toHaveCount(3)
        ->and(collect($mine)->pluck('exam.id')->unique()->all())->toBe([$exam['id']])
        ->and(collect($mine)->pluck('paper.subject.code')->sort()->values()->all())->toBe(['BAN1', 'ENG1', 'MATH'])
        ->and($mine[0]['section']['name'])->toBe($this->school->section['name'])
        ->and($mine[0]['mine'])->toBeTrue();
    expect(($this->as)($other->token)->getJson(($this->exams)('sheets'))->json('data'))->toBe([]);
    // The owner manages exams but is no teacher (no HRM employee): no sheets of their own.
    expect(($this->as)()->getJson(($this->exams)('sheets'))->json('data'))->toBe([]);

    // Named on a sheet: it moves to that teacher's list.
    ($this->as)()->postJson(($this->exams)("sheets/{$mine[0]['id']}/assign"), ['teacher_id' => $other->employee, 'base_version' => $mine[0]['version']])->assertOk();
    expect(collect(($this->as)($other->token)->getJson(($this->exams)('sheets'))->json('data'))->pluck('id')->all())->toBe([$mine[0]['id']])
        ->and(($this->as)($classTeacher->token)->getJson(($this->exams)('sheets'))->json('data'))->toHaveCount(2);

    // One sheet tells what grades it: the exam's scheme and the paper's pass marks.
    $sheet = ($this->as)($classTeacher->token)->getJson(($this->exams)("sheets/{$mine[1]['id']}"))->assertOk()->json('data');
    expect($sheet['exam'])->toMatchArray(['id' => $exam['id'], 'status' => 'marking', 'grading_scheme_id' => $exam['grading_scheme_id']])
        ->and($sheet['paper'])->toMatchArray(['full_marks_centi' => 10000, 'pass_marks_centi' => 3300])
        ->and($sheet['section']['id'])->toBe($this->school->section['id']);

    // Managers get the teachers to pick and every rule a screen follows; a teacher gets no list of staff.
    $setup = ($this->setup)();
    expect(collect($setup['teachers'])->pluck('id')->all())->toContain($classTeacher->employee, $other->employee)
        ->and($setup['hrm'])->toBeTrue()
        ->and($setup['rules'])->toHaveKeys(['moderation_required', 'show_rank', 'gpa_by_credits', 'correction_needs_approval']);
    expect(($this->as)($classTeacher->token)->getJson(($this->exams)('setup'))->json('data.teachers'))->toBe([]);

    // Another institution sees none of them; exams off answers 403.
    foreach ([$this->w->c2, $this->w->c4] as $place) {
        expect($this->asToken(orgToken(createMember($place), $place))->getJson(($this->exams)('sheets', $place))->json('data'))->toBe([]);
    }
    toggles()->disable($this->w->g1, 'education_exams', 'Test');
    ($this->as)($classTeacher->token)->getJson(($this->exams)('sheets'))->assertForbidden();
});

it('keeps a paper of one part at the paper\'s full marks when they change', function () {
    $exam = ($this->exam)();
    $paper = ($this->as)()->postJson(($this->exams)("{$exam['id']}/papers"), ['level_id' => $this->school->class6['id'], 'subject_id' => $this->subjects['BAN1']['id'], 'full_marks_centi' => 10000])->assertCreated()->json('data');
    expect($paper['parts'])->toHaveCount(1)->and($paper['parts'][0]['full_marks_centi'])->toBe(10000);

    $half = ($this->as)()->patchJson(($this->exams)("{$exam['id']}/papers/{$paper['id']}"), ['full_marks_centi' => 5000, 'base_version' => $paper['version']])->assertOk()->json('data');
    expect($half['full_marks_centi'])->toBe(5000)->and($half['parts'][0]['full_marks_centi'])->toBe(5000);

    // Split and back to one part again.
    $split = ($this->as)()->patchJson(($this->exams)("{$exam['id']}/papers/{$paper['id']}"), ['parts' => [['code' => 'CQ', 'full_marks_centi' => 3500], ['code' => 'MCQ', 'full_marks_centi' => 1500]], 'base_version' => $half['version']])->assertOk()->json('data');
    $whole = ($this->as)()->patchJson(($this->exams)("{$exam['id']}/papers/{$paper['id']}"), ['parts' => [['code' => 'TOTAL', 'name' => null, 'full_marks_centi' => 5000]], 'base_version' => $split['version']])->assertOk()->json('data');
    expect(collect($whole['parts'])->pluck('code')->all())->toBe(['TOTAL']);
});

it('shows the institution\'s exam to a campus teacher, counting that campus only, and leaves changing it to the institution', function () {
    $exam = ($this->exam)();
    ($this->as)()->postJson(($this->exams)("{$exam['id']}/papers"), ['level_id' => $this->school->class6['id'], 'subject_id' => $this->subjects['BAN1']['id'], 'full_marks_centi' => 10000])->assertCreated();
    $exam = ($this->as)()->postJson(($this->exams)("{$exam['id']}/status"), ['status' => 'marking', 'base_version' => $exam['version']])->assertOk()->json('data');

    // A teacher who works at the campus (B1), class teacher of section A there.
    $campus = $this->w->b1;
    toggles()->enable($this->w->g1, 'hrm', 'Test setup');
    $user = createMember($campus, MembershipType::Staff);
    giveRoles($user, $campus, makeRole($this->w->c1, ['education.view', 'education_exams.view', 'education_exams.enter_marks'], 'Campus teacher'));
    $employee = hireVia($this, $this->token, $this->w->c1, ['full_name' => 'Campus Teacher', 'joined_on' => '2026-01-01'])->assertCreated()->json('data.id');
    DB::table('hrm_employees')->where('id', $employee)->update(['user_id' => $user->id]);
    $section = ($this->as)()->getJson(($this->api)("structure/sections?session_id={$this->school->session['id']}"))->json('data.0');
    ($this->as)()->patchJson(($this->api)("structure/sections/{$this->school->section['id']}"), ['base_version' => $section['version'], 'class_teacher_id' => $employee])->assertOk();
    $teacher = orgToken($user, $campus);

    $mine = ($this->as)($teacher)->getJson(($this->exams)('sheets', $campus))->assertOk()->json('data');
    expect($mine)->toHaveCount(1)->and($mine[0]['exam']['id'])->toBe($exam['id']);
    expect(collect(($this->as)($teacher)->getJson(($this->exams)('', $campus))->json('data'))->pluck('id')->all())->toBe([$exam['id']]);
    expect(($this->as)($teacher)->getJson(($this->exams)($exam['id'], $campus))->assertOk()->json('data.counts'))->toBe(['open' => 1]);
    ($this->as)($teacher)->getJson(($this->exams)("{$exam['id']}/sheets", $campus))->assertOk()->assertJsonCount(1, 'data');
    ($this->as)($teacher)->getJson(($this->exams)("sheets/{$mine[0]['id']}", $campus))->assertOk()->assertJsonPath('data.mine', true);

    // A campus manager reads it but cannot change the institution's exam (it looks unknown there).
    $manager = createMember($campus, MembershipType::Staff);
    giveRoles($manager, $campus, makeRole($this->w->c1, ['education.view', 'education_exams.view', 'education_exams.manage'], 'Campus exams'));
    $managerToken = orgToken($manager, $campus);
    ($this->as)($managerToken)->getJson(($this->exams)($exam['id'], $campus))->assertOk();
    ($this->as)($managerToken)->patchJson(($this->exams)($exam['id'], $campus), ['kind' => 'final', 'base_version' => $exam['version']])->assertNotFound();
    ($this->as)($managerToken)->postJson(($this->exams)("{$exam['id']}/status", $campus), ['status' => 'closed', 'base_version' => $exam['version']])->assertNotFound();

    // Another campus of the institution sees the exam with none of B1's sheets.
    $campus2 = createChild($this->w->c1, OrganizationType::Branch, 'B1 North');
    $other = createMember($campus2, MembershipType::Staff);
    giveRoles($other, $campus2, makeRole($this->w->c1, ['education.view', 'education_exams.view'], 'Other campus'));
    $otherToken = orgToken($other, $campus2);
    expect(($this->as)($otherToken)->getJson(($this->exams)($exam['id'], $campus2))->json('data.counts'))->toBe([])
        ->and(($this->as)($otherToken)->getJson(($this->exams)("{$exam['id']}/sheets", $campus2))->json('data'))->toBe([]);
});
