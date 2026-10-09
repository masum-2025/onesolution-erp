<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Portal\Jobs\SendPortalInvitation;
use App\Platform\Portal\Models\PortalLink;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Modules\Education\Events\StudentAdmitted;
use Modules\Education\Models\Enrollment;
use Modules\Education\Models\Guardian;
use Modules\Education\Models\Student;

/*
 * EDU-1a: an education institution set up from a preset (programs by year,
 * semester or term, levels that promote to the next, lists, subjects and own
 * fields), sessions and sections at campuses, students with guardians (one
 * phone, one guardian), applications through their decisions to admission,
 * enrollments with rolls and capacity, private details (encrypted, shown and
 * changed only with education.view_sensitive, never in the audit), teachers
 * who see their own sections, the student in the portal, and isolation.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-10 04:00:00', 'UTC'));
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'education', 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/education/{$path}";
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);
});

// ── Structure ──

it('sets up an institution from a preset once, with levels that promote to the next', function () {
    $made = ($this->as)()->postJson(($this->api)('presets/bd_school/apply'))->assertOk()->json('data.made');
    expect($made['programs'])->toBe(3)->and($made['levels'])->toBe(13)->and($made['fields'])->toBeGreaterThan(0);

    // Applying again makes nothing twice.
    expect(array_sum(($this->as)()->postJson(($this->api)('presets/bd_school/apply'))->assertOk()->json('data.made')))->toBe(0);

    $levels = collect(($this->as)()->getJson(($this->api)('structure/levels'))->json('data'));
    $c9 = $levels->firstWhere('code', 'C9');
    expect($levels->firstWhere('id', $c9['next_level_id'])['code'])->toBe('C10')
        ->and($levels->firstWhere('code', 'C10')['next_level_id'])->toBeNull()
        ->and($levels->firstWhere('code', 'C6')['name'])->toMatchArray(['en' => 'Class 6', 'bn' => 'শ্রেণি ৬']);

    // A university (semesters, credits) in another company from its own preset.
    $other = createMember($this->w->c2);
    $this->asToken(orgToken($other, $this->w->c2))->postJson(($this->api)('presets/university/apply', $this->w->c2))->assertOk();
    $programs = collect($this->asToken(orgToken($other, $this->w->c2))->getJson(($this->api)('structure/programs', $this->w->c2))->json('data'));
    expect($programs->firstWhere('code', 'BSCSE'))->toMatchArray(['progression' => 'semester', 'periods_per_year' => 2, 'total_credits_centi' => 16000])
        ->and($programs->pluck('code'))->not->toContain('SEC');

    ($this->as)()->postJson(($this->api)('presets/no_such/apply'))->assertNotFound();
    ($this->as)()->postJson(($this->api)('presets/_common_lists/apply'))->assertNotFound();
    expect(AuditLog::where('action', 'education.preset_applied')->count())->toBe(3);
});

it('keeps the structure consistent', function () {
    $school = eduSchool($this);
    $pri = collect($school->setup['programs'])->firstWhere('code', 'PRI');
    $class1 = collect($school->setup['levels'])->first(fn ($level) => $level['program_id'] === $pri['id'] && $level['code'] === 'C1');

    // A level promotes only within its program.
    ($this->as)()->patchJson(($this->api)("structure/levels/{$class1['id']}"), ['base_version' => $class1['version'], 'next_level_id' => $school->class6['id']])
        ->assertUnprocessable()->assertJsonPath('code', 'mismatch_next_level_id');
    // A session sits inside its year; a semester session does not fit a program taught by year.
    ($this->as)()->postJson(($this->api)('structure/sessions'), ['academic_year_id' => $school->year['id'], 'kind' => 'year', 'sequence' => 2, 'name' => ['en' => 'X'], 'starts_on' => '2025-12-01', 'ends_on' => '2026-03-01'])
        ->assertUnprocessable()->assertJsonValidationErrors('starts_on');
    $spring = ($this->as)()->postJson(($this->api)('structure/sessions'), ['academic_year_id' => $school->year['id'], 'kind' => 'semester', 'name' => ['en' => 'Spring'], 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30'])->assertCreated()->json('data');
    ($this->as)()->postJson(($this->api)('structure/sections'), ['unit_id' => $this->w->b1->id, 'session_id' => $spring['id'], 'level_id' => $school->class6['id'], 'name' => 'B'])
        ->assertUnprocessable()->assertJsonPath('code', 'mismatch_session_id');
    // A shift must be a shift; a section of the same name twice is refused; capacity comes from the rule.
    $medium = collect($school->setup['lists'])->first(fn ($item) => $item['kind'] === 'medium');
    ($this->as)()->postJson(($this->api)('structure/sections'), ['unit_id' => $this->w->b1->id, 'session_id' => $school->session['id'], 'level_id' => $school->class6['id'], 'name' => 'B', 'shift_id' => $medium['id']])
        ->assertUnprocessable()->assertJsonValidationErrors('shift_id');
    ($this->as)()->postJson(($this->api)('structure/sections'), ['unit_id' => $this->w->b1->id, 'session_id' => $school->session['id'], 'level_id' => $school->class6['id'], 'name' => 'A'])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
    $b = ($this->as)()->postJson(($this->api)('structure/sections'), ['unit_id' => $this->w->b1->id, 'session_id' => $school->session['id'], 'level_id' => $school->class6['id'], 'name' => 'B'])->assertCreated()->json('data');
    expect($b['capacity'])->toBe(40);
    // A section only at a campus of this institution.
    ($this->as)()->postJson(($this->api)('structure/sections'), ['unit_id' => $this->w->b2->id, 'session_id' => $school->session['id'], 'level_id' => $school->class6['id'], 'name' => 'C'])
        ->assertNotFound();

    // Prerequisites never go round in a circle.
    $subjects = collect(($this->as)()->getJson(($this->api)('structure/subjects'))->json('data'))->keyBy('code');
    ($this->as)()->postJson(($this->api)('structure/prerequisites'), ['subject_id' => $subjects['HMATH']['id'], 'requires_subject_id' => $subjects['MATH']['id']])->assertCreated();
    ($this->as)()->postJson(($this->api)('structure/prerequisites'), ['subject_id' => $subjects['MATH']['id'], 'requires_subject_id' => $subjects['HMATH']['id']])
        ->assertUnprocessable()->assertJsonPath('code', 'mismatch_requires_subject_id');

    // Unknown kinds, unknown fields, and a stale version.
    ($this->as)()->getJson(($this->api)('structure/teachers'))->assertNotFound();
    ($this->as)()->postJson(($this->api)('structure/subjects'), ['code' => 'X1', 'name' => ['en' => 'X'], 'secret' => 1])->assertUnprocessable();
    ($this->as)()->patchJson(($this->api)("structure/sections/{$b['id']}"), ['base_version' => 99, 'capacity' => 30])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
});

it('lets only people who manage the institution change its structure', function () {
    $looker = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view'], 'Looker'));
    $token = orgToken($looker, $this->w->c1);
    $this->asToken($token)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can.manage', false);
    $this->asToken($token)->postJson(($this->api)('presets/bd_school/apply'))->assertForbidden();
    $this->asToken($token)->postJson(($this->api)('structure/subjects'), ['code' => 'X1', 'name' => ['en' => 'X']])->assertForbidden();
});

// ── Students and guardians ──

it('admits a student directly with a code, guardians and a section; siblings share a guardian', function () {
    Event::fake([StudentAdmitted::class]);
    $school = eduSchool($this);
    orgRule($this->w->c1, 'education.student_code_format', '{PROGRAM}-{YY}-{SEQ:3}');

    $rahim = newStudent($this, $school)->assertCreated()->json('data');
    expect($rahim)->toMatchArray(['code' => 'SEC-26-001', 'status' => 'active', 'unit_id' => $this->w->b1->id])
        ->and($rahim['enrollment'])->toMatchArray(['section_id' => $school->section['id'], 'roll_no' => 1, 'status' => 'active']);

    $karima = newStudent($this, $school, ['name' => 'Karima', 'gender' => 'female', 'guardians' => [['name' => 'Abdul Karim', 'phone' => '+8801711000000', 'relation' => 'father']]])->assertCreated()->json('data');
    expect($karima['code'])->toBe('SEC-26-002')->and($karima['enrollment']['roll_no'])->toBe(2)
        ->and(Guardian::count())->toBe(1);

    // The section takes two: a third student is told it is full.
    newStudent($this, $school, ['name' => 'Third', 'guardians' => []])->assertStatus(409)->assertJsonPath('code', 'section_full');

    $shown = ($this->as)()->getJson(($this->api)("students/{$rahim['id']}"))->assertOk()->json('data');
    expect($shown['guardians'][0])->toMatchArray(['name' => 'Abdul Karim', 'phone' => '+8801711000000', 'relation' => 'father', 'is_primary' => true])
        ->and($shown['enrollments'])->toHaveCount(1);

    // Search by name, code and phone (Bangla digits too); unknown fields and genders are refused.
    ($this->as)()->getJson(($this->api)('students?q=karima'))->assertOk()->assertJsonPath('meta.total', 1);
    ($this->as)()->getJson(($this->api)('students?q=SEC-26-001'))->assertOk()->assertJsonPath('data.0.id', $rahim['id']);
    newStudent($this, $school, ['name' => 'X', 'gender' => 'robot', 'enrollment' => null, 'guardians' => []])->assertUnprocessable()->assertJsonValidationErrors('gender');
    newStudent($this, $school, ['name' => 'X', 'extra' => ['blood_group' => 'Z+'], 'guardians' => []])->assertUnprocessable()->assertJsonValidationErrors('extra.blood_group');

    // The same op id (a retry) is the same student.
    $first = newStudent($this, $school, ['name' => 'Retry', 'op_id' => 'op-1', 'enrollment' => null, 'guardians' => []])->assertCreated()->json('data.id');
    expect(newStudent($this, $school, ['name' => 'Retry', 'op_id' => 'op-1', 'enrollment' => null, 'guardians' => []])->assertOk()->json('data.id'))->toBe($first);

    Event::assertDispatchedTimes(StudentAdmitted::class, 3);
});

it('keeps private details encrypted, out of the audit, and to people allowed to see them', function () {
    $school = eduSchool($this);
    $clerk = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view', 'education.admit', 'education.edit_students'], 'Clerk'));
    $clerkToken = orgToken($clerk, $this->w->c1);

    // Sending private details needs the permission.
    newStudent($this, $school, ['date_of_birth' => '2014-02-01'], $clerkToken)->assertForbidden();
    newStudent($this, $school, ['extra' => ['religion' => 'islam']], $clerkToken)->assertForbidden();
    $student = newStudent($this, $school, ['date_of_birth' => '2014-02-01', 'birth_registration_no' => '2014-2692-1234567', 'extra' => ['religion' => 'islam', 'blood_group' => 'B+']])->assertCreated()->json('data');
    expect($student)->toMatchArray(['date_of_birth' => '2014-02-01', 'birth_registration_no' => '2014-2692-1234567'])
        ->and((array) $student['extra'])->toMatchArray(['religion' => 'islam', 'blood_group' => 'B+']);

    $seen = $this->asToken($clerkToken)->getJson(($this->api)("students/{$student['id']}"))->assertOk()->json('data');
    expect($seen['date_of_birth'])->toBeNull()->and($seen['birth_registration_no'])->toBeNull()
        ->and((array) $seen['extra'])->toBe(['blood_group' => 'B+']);

    // Encrypted at rest; the audit never holds the values.
    expect(DB::table('edu_students')->where('id', $student['id'])->value('birth_registration_no'))->not->toContain('1234567');
    $audit = json_encode(AuditLog::where('action', 'education.student_created')->get()->pluck('new_values'));
    expect($audit)->not->toContain('1234567')->not->toContain('2014-02-01');

    // The same number twice is the same child: refused, naming the student.
    newStudent($this, $school, ['name' => 'Again', 'birth_registration_no' => '20142692 1234567', 'enrollment' => null, 'guardians' => []])
        ->assertStatus(409)->assertJsonPath('code', 'duplicate_student');

    // Opening private details is audited.
    ($this->as)()->getJson(($this->api)("students/{$student['id']}"))->assertOk();
    expect(AuditLog::where('action', 'education.student_viewed_sensitive')->count())->toBe(1);
});

it('changes a student with the version read, moves them between sections and numbers rolls', function () {
    $school = eduSchool($this);
    $b = ($this->as)()->postJson(($this->api)('structure/sections'), ['unit_id' => $this->w->b1->id, 'session_id' => $school->session['id'], 'level_id' => $school->class6['id'], 'name' => 'B'])->json('data');
    $zara = newStudent($this, $school, ['name' => 'Zara'])->json('data');
    $amin = newStudent($this, $school, ['name' => 'Amin', 'guardians' => []])->json('data');

    ($this->as)()->patchJson(($this->api)("students/{$zara['id']}"), ['base_version' => $zara['version'], 'name_local' => 'জারা'])->assertOk()->assertJsonPath('data.name_local', 'জারা');
    ($this->as)()->patchJson(($this->api)("students/{$zara['id']}"), ['base_version' => $zara['version'], 'name' => 'Old'])->assertStatus(409);

    // By name: Amin 1, Zara 2.
    orgRule($this->w->c1, 'education.roll_number_mode', 'name');
    ($this->as)()->postJson(($this->api)("sections/{$school->section['id']}/rolls"))->assertOk()->assertJsonPath('data.count', 2);
    $roster = ($this->as)()->getJson(($this->api)("sections/{$school->section['id']}/students"))->assertOk()->json('data.students');
    expect(array_column($roster, 'name'))->toBe(['Amin', 'Zara'])->and(array_column($roster, 'roll_no'))->toBe([1, 2]);

    // By hand: one roll per student.
    ($this->as)()->postJson(($this->api)("sections/{$school->section['id']}/rolls"), ['rolls' => [['enrollment_id' => $roster[0]['id'], 'roll_no' => 2]]])->assertUnprocessable();
    ($this->as)()->postJson(($this->api)("sections/{$school->section['id']}/rolls"), ['rolls' => [['enrollment_id' => $roster[0]['id'], 'roll_no' => 7], ['enrollment_id' => $roster[1]['id'], 'roll_no' => 3]]])->assertOk();

    // To section B (same session and level).
    $enrollment = Enrollment::where('student_id', $zara['id'])->sole();
    ($this->as)()->postJson(($this->api)("enrollments/{$enrollment->id}/place"), ['base_version' => $enrollment->version, 'section_id' => $b['id']])
        ->assertOk()->assertJsonPath('data.section_id', $b['id']);
    expect(AuditLog::where('action', 'education.enrollment_placed')->count())->toBe(1);

    // Leaving ends the enrollment and keeps the student.
    $fresh = ($this->as)()->getJson(($this->api)("students/{$zara['id']}"))->json('data');
    ($this->as)()->postJson(($this->api)("students/{$zara['id']}/leave"), ['base_version' => $fresh['version'], 'status' => 'left', 'reason' => 'Moved to another town'])
        ->assertOk()->assertJsonPath('data.status', 'left');
    expect(Enrollment::where('student_id', $zara['id'])->sole()->status)->toBe('left');
});

it('links, changes and unlinks guardians; one phone is one guardian', function () {
    $school = eduSchool($this);
    $student = newStudent($this, $school)->json('data');
    $mother = ($this->as)()->postJson(($this->api)("students/{$student['id']}/guardians"), ['name' => 'Rokeya', 'phone' => '01811-000000', 'relation' => 'mother', 'is_primary' => true])
        ->assertCreated()->json('data');
    expect($mother['is_primary'])->toBeTrue();

    $guardians = ($this->as)()->getJson(($this->api)("students/{$student['id']}"))->json('data.guardians');
    expect(collect($guardians)->where('is_primary', true))->toHaveCount(1);

    $father = collect($guardians)->firstWhere('relation', 'father');
    ($this->as)()->patchJson(($this->api)("students/{$student['id']}/guardians/{$father['id']}"), ['base_version' => $father['version'], 'phone' => '01811-000000'])
        ->assertUnprocessable()->assertJsonValidationErrors('phone');
    ($this->as)()->postJson(($this->api)("students/{$student['id']}/guardians"), ['name' => 'X', 'relation' => 'uncle'])->assertUnprocessable()->assertJsonValidationErrors('relation');
    ($this->as)()->deleteJson(($this->api)("students/{$student['id']}/guardians/{$mother['id']}"))->assertOk();
    expect(AuditLog::where('action', 'education.guardian_unlinked')->count())->toBe(1);
});

it('keeps a student photo private, opened only through a short-lived link', function () {
    Storage::fake('local');
    $school = eduSchool($this);
    $student = newStudent($this, $school)->json('data');

    ($this->as)()->post(($this->api)("students/{$student['id']}/photo"), ['photo' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')])
        ->assertUnprocessable()->assertJsonPath('code', 'bad_photo');
    $url = ($this->as)()->post(($this->api)("students/{$student['id']}/photo"), ['photo' => UploadedFile::fake()->image('face.jpg', 200, 240)])
        ->assertOk()->json('data.photo_url');

    expect($url)->toStartWith('/files/education/');
    $this->get($url)->assertOk();
    $this->get(strtok($url, '?'))->assertForbidden();
});

// ── Admissions ──

it('takes an application through its decisions to admission', function () {
    $school = eduSchool($this);
    $application = ($this->as)()->postJson(($this->api)('admissions'), [
        'unit_id' => $this->w->b1->id, 'program_id' => $school->program['id'], 'level_id' => $school->class6['id'], 'session_id' => $school->session['id'],
        'applicant' => ['name' => 'Nusrat', 'gender' => 'female', 'date_of_birth' => '2014-05-01', 'guardians' => [['name' => 'Rafiq', 'phone' => '01911-000000', 'relation' => 'father']]],
        'op_id' => 'adm-1',
    ])->assertCreated()->json('data');
    expect($application)->toMatchArray(['number' => 'ADM-2026-0001', 'status' => 'applied']);

    // A decision may only follow the one before; admitting is its own step.
    ($this->as)()->postJson(($this->api)("admissions/{$application['id']}/step"), ['base_version' => 1, 'status' => 'admitted'])->assertUnprocessable();
    ($this->as)()->postJson(($this->api)("admissions/{$application['id']}/step"), ['base_version' => 1, 'status' => 'offered', 'note' => 'Passed the test'])->assertOk();
    ($this->as)()->postJson(($this->api)("admissions/{$application['id']}/step"), ['base_version' => 2, 'status' => 'test'])->assertStatus(409)->assertJsonPath('code', 'wrong_status');

    $student = ($this->as)()->postJson(($this->api)("admissions/{$application['id']}/admit"), ['base_version' => 2, 'section_id' => $school->section['id']])
        ->assertCreated()->json('data');
    expect($student)->toMatchArray(['name' => 'Nusrat', 'admission_no' => 'ADM-2026-0001', 'date_of_birth' => '2014-05-01'])
        ->and($student['enrollment']['section_id'])->toBe($school->section['id'])
        ->and(Student::find($student['id'])->admitted_on->toDateString())->toBe('2026-01-10');
    ($this->as)()->getJson(($this->api)("admissions/{$application['id']}"))->assertJsonPath('data.status', 'admitted')->assertJsonPath('data.student_id', $student['id']);
    ($this->as)()->postJson(($this->api)("admissions/{$application['id']}/admit"), ['base_version' => 3])->assertStatus(409);

    // Applications need education.admit.
    $looker = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view'], 'Looker'));
    $this->asToken(orgToken($looker, $this->w->c1))->getJson(($this->api)('admissions'))->assertForbidden();
    expect(AuditLog::where('action', 'education.admission_admitted')->count())->toBe(1);
});

// ── Who sees what ──

it('shows a teacher the students of their own sections, or all by the rule', function () {
    $school = eduSchool($this);
    $mine = newStudent($this, $school, ['name' => 'Mine'])->json('data');
    $b = ($this->as)()->postJson(($this->api)('structure/sections'), ['unit_id' => $this->w->b1->id, 'session_id' => $school->session['id'], 'level_id' => $school->class6['id'], 'name' => 'B'])->json('data');
    $other = newStudent($this, $school, ['name' => 'Other', 'guardians' => [], 'enrollment' => ['session_id' => $school->session['id'], 'level_id' => $school->class6['id'], 'section_id' => $b['id']]])->json('data');

    $teacher = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view'], 'Teacher'));
    $token = orgToken($teacher, $this->w->c1);
    // Not linked to an employee: no sections, no students.
    $this->asToken($token)->getJson(($this->api)('students'))->assertOk()->assertJsonPath('meta.total', 0);

    toggles()->enable($this->w->g1, 'hrm', 'Test setup');
    $employee = hireVia($this, $this->token, $this->w->c1, ['full_name' => 'Teacher One'])->assertCreated()->json('data.id');
    // HR links the teacher's login to the employee (HRM's own screen; set directly here).
    DB::table('hrm_employees')->where('id', $employee)->update(['user_id' => $teacher->id]);
    $section = ($this->as)()->getJson(($this->api)("structure/sections?session_id={$school->session['id']}"))->json('data.0');
    ($this->as)()->patchJson(($this->api)("structure/sections/{$school->section['id']}"), ['base_version' => $section['version'], 'class_teacher_id' => $employee])->assertOk();

    $this->asToken($token)->getJson(($this->api)('students'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine['id']);
    $this->asToken($token)->getJson(($this->api)("students/{$other['id']}"))->assertNotFound();
    $this->asToken($token)->getJson(($this->api)("sections/{$b['id']}/students"))->assertNotFound();

    orgRule($this->w->c1, 'education.teacher_scope', 'all');
    $this->asToken($token)->getJson(($this->api)('students'))->assertOk()->assertJsonPath('meta.total', 2);
});

it('keeps campuses, institutions and partners apart', function () {
    $school = eduSchool($this);
    $student = newStudent($this, $school)->json('data');

    // Another company, another partner: the same 404 as an unknown id.
    $c2 = createMember($this->w->c2);
    $this->asToken(orgToken($c2, $this->w->c2))->getJson(($this->api)("students/{$student['id']}", $this->w->c2))->assertNotFound();
    $c4 = createMember($this->w->c4);
    $this->asToken(orgToken($c4, $this->w->c4))->getJson(($this->api)("students/{$student['id']}", $this->w->c4))->assertNotFound();
    $this->asToken(orgToken($c4, $this->w->c4))->getJson(($this->api)('students', $this->w->c1))->assertNotFound();

    // A person of another campus of the same institution does not see this campus's students.
    $branchOwner = createMember($this->w->b1);
    $this->asToken(orgToken($branchOwner, $this->w->b1))->getJson(($this->api)('students', $this->w->b1))->assertOk()->assertJsonPath('meta.total', 1);
    $d1 = createMember($this->w->d1);
    $this->asToken(orgToken($d1, $this->w->d1))->getJson(($this->api)("students/{$student['id']}", $this->w->d1))->assertNotFound();
});

it('answers 403 while Education is off, and keeps the data', function () {
    $school = eduSchool($this);
    newStudent($this, $school)->assertCreated();
    toggles()->disable($this->w->g1, 'education', 'Test', confirm: true);

    ($this->as)()->getJson(($this->api)('students'))->assertForbidden();
    expect(Student::count())->toBe(1);
});

// ── Portal and export ──

it('shows a guardian their own child in the portal, without private details', function () {
    Bus::fake([SendPortalInvitation::class]);
    RateLimiter::clear('portal-join:127.0.0.1');
    toggles()->enable($this->w->g1, 'client_portal', 'Test setup');
    $school = eduSchool($this);
    $child = newStudent($this, $school, ['date_of_birth' => '2014-02-01', 'extra' => ['religion' => 'islam', 'blood_group' => 'B+']])->json('data');
    newStudent($this, $school, ['name' => 'Someone else', 'guardians' => []])->assertCreated();

    $invitation = $this->asToken($this->token)->postJson("/api/organizations/{$this->w->c1->id}/portal/invitations", [
        'subject_type' => 'education.student', 'subject_id' => $child['id'], 'relation' => 'guardian',
        'name' => 'Abdul Karim', 'channel' => 'mail', 'email' => 'father@example.com',
    ])->assertCreated()->json('data');
    $parent = User::factory()->create(['email' => 'father@example.com', 'email_verified_at' => now()]);
    $this->withHeader('Origin', config('app.url'))->actingAs($parent, 'web')->postJson('/session/portal/join', ['key' => $invitation['code']])->assertOk();
    $link = PortalLink::query()->sole();
    $this->asToken($this->token)->postJson("/api/organizations/{$this->w->c1->id}/portal/links/{$link->id}/approve")->assertOk();

    $fields = $this->asToken(orgToken($parent, $this->w->c1))->getJson("/api/portal/records/{$link->id}")->assertOk()->json('data.fields');
    $values = collect($fields)->pluck('value', 'key');
    expect($values['name'])->toBe('Rahim Uddin')
        ->and($values['section'])->toBe('A')
        ->and($values['extra.blood_group'])->toBe('B+')
        ->and($values->keys())->not->toContain('extra.religion')
        ->and(json_encode($fields))->not->toContain('2014-02-01');
});
