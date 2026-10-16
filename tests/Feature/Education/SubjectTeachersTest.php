<?php

use App\Platform\Tenancy\Enums\OrganizationType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Education\Directory\AcademicDirectory;

/*
 * EDU-5a: who teaches which subject in a section. Set and changed (version)
 * by who sets the institution up, one teacher per subject and section, a
 * teacher from HRM; the section's page lists its curriculum subjects with
 * their teachers; a subject teacher sees their sections like a class
 * teacher; campus, company and module off.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2] as $group) {
        toggles()->enable($group, 'education', 'Test setup');
        toggles()->enable($group, 'hrm', 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/education/{$path}";
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);

    $this->school = eduSchool($this);
    $this->subjects = collect(($this->as)()->getJson(($this->api)('structure/subjects'))->json('data'))->keyBy('code');
    $curriculum = ($this->as)()->postJson(($this->api)('structure/curricula'), ['program_id' => $this->school->program['id'], 'name' => '2026', 'effective_from_year' => 2026, 'status' => 'active'])->assertCreated()->json('data');
    foreach (['BAN1' => 'compulsory', 'MATH' => 'compulsory', 'ENG1' => 'elective'] as $code => $kind) {
        ($this->as)()->postJson(($this->api)('structure/curriculum_items'), ['curriculum_id' => $curriculum['id'], 'level_id' => $this->school->class6['id'], 'subject_id' => $this->subjects[$code]['id'], 'kind' => $kind])->assertCreated();
    }
    $this->rahim = newStudent($this, $this->school, ['name' => 'Rahim', 'guardians' => []])->assertCreated()->json('data');

    // A teacher: an HRM employee whose login is linked, who may read Education.
    $this->teacher = function (string $name) {
        $user = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view'], "Teacher {$name}"));
        $employee = hireVia($this, $this->token, $this->w->c1, ['full_name' => $name, 'joined_on' => '2026-01-01'])->assertCreated()->json('data.id');
        DB::table('hrm_employees')->where('id', $employee)->update(['user_id' => $user->id]);

        return (object) ['user' => $user, 'token' => orgToken($user, $this->w->c1), 'employee' => $employee];
    };
    $this->assign = fn (string $code, string $teacher, ?string $token = null) => ($this->as)($token)->postJson(($this->api)('structure/subject_teachers'), [
        'section_id' => $this->school->section['id'], 'subject_id' => $this->subjects[$code]['id'], 'teacher_id' => $teacher,
    ]);
    $this->roster = fn (?string $token = null) => ($this->as)($token)->getJson(($this->api)("sections/{$this->school->section['id']}/students"));
});

it('names a teacher for a subject of a section, changes and takes them out, and the section page shows it', function () {
    $rina = ($this->teacher)('Rina Akter');
    $kamal = ($this->teacher)('Kamal Hossain');

    $made = ($this->assign)('MATH', $rina->employee)->assertCreated()->json('data');
    expect($made)->toMatchArray(['section_id' => $this->school->section['id'], 'subject_id' => $this->subjects['MATH']['id'], 'teacher_id' => $rina->employee, 'version' => 1]);

    // The page: curriculum subjects in order, each with its teacher (or none yet).
    $subjects = collect(($this->roster)()->assertOk()->json('data.subjects'))->keyBy('code');
    expect($subjects->keys()->sort()->values()->all())->toBe(['BAN1', 'ENG1', 'MATH'])
        ->and($subjects['MATH']['assignment'])->toMatchArray(['teacher_id' => $rina->employee, 'teacher_name' => 'Rina Akter'])
        ->and($subjects['BAN1']['assignment'])->toBeNull()
        ->and($subjects['ENG1']['kind'])->toBe('elective');

    // Change with the version read; an old version is refused.
    ($this->as)()->patchJson(($this->api)("structure/subject_teachers/{$made['id']}"), ['teacher_id' => $kamal->employee, 'base_version' => 1])->assertOk()->assertJsonPath('data.version', 2);
    ($this->as)()->patchJson(($this->api)("structure/subject_teachers/{$made['id']}"), ['teacher_id' => $rina->employee, 'base_version' => 1])->assertStatus(409);
    expect(app(AcademicDirectory::class)->subjectTeachers($this->w->c1, [$this->school->section['id']]))->toBe([
        ['section_id' => $this->school->section['id'], 'subject_id' => $this->subjects['MATH']['id'], 'teacher_id' => $kamal->employee],
    ]);

    ($this->as)()->deleteJson(($this->api)("structure/subject_teachers/{$made['id']}"))->assertOk();
    expect(collect(($this->roster)()->json('data.subjects'))->firstWhere('code', 'MATH')['assignment'])->toBeNull();
});

it('refuses an unknown teacher, a second teacher for the same subject, and moving a row to another subject', function () {
    $rina = ($this->teacher)('Rina Akter');
    ($this->assign)('MATH', '01aaaaaaaaaaaaaaaaaaaaaaaa')->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
    ($this->assign)('MATH', 'short')->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
    $made = ($this->assign)('MATH', $rina->employee)->assertCreated()->json('data');
    ($this->assign)('MATH', $rina->employee)->assertUnprocessable()->assertJsonValidationErrors('subject_id');
    ($this->as)()->patchJson(($this->api)("structure/subject_teachers/{$made['id']}"), ['subject_id' => $this->subjects['BAN1']['id'], 'teacher_id' => $rina->employee, 'base_version' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('subject_id');
    ($this->as)()->postJson(($this->api)('structure/subject_teachers'), ['section_id' => $this->school->section['id'], 'subject_id' => $this->subjects['BAN1']['id']])
        ->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
});

it('lets a subject teacher see their section, as a class teacher does, and nothing else', function () {
    $rina = ($this->teacher)('Rina Akter');
    // Before they teach anything here, the section is not theirs.
    ($this->roster)($rina->token)->assertNotFound();
    ($this->as)($rina->token)->getJson(($this->api)("students/{$this->rahim['id']}"))->assertNotFound();

    ($this->assign)('MATH', $rina->employee)->assertCreated();
    ($this->roster)($rina->token)->assertOk()->assertJsonPath('data.students.0.student_id', $this->rahim['id']);
    ($this->as)($rina->token)->getJson(($this->api)("students/{$this->rahim['id']}"))->assertOk();
    expect(app(AcademicDirectory::class)->teaching($this->w->c1, $rina->employee, [$this->w->b1->id]))->toBe([
        ['section_id' => $this->school->section['id'], 'subject_id' => $this->subjects['MATH']['id']],
    ]);

    // Reading is theirs; naming teachers is not.
    ($this->as)($rina->token)->getJson(($this->api)("structure/subject_teachers?section_id={$this->school->section['id']}"))->assertOk()->assertJsonCount(1, 'data');
    ($this->assign)('BAN1', $rina->employee, $rina->token)->assertForbidden();
});

it('keeps to the section\'s campus and company, and to the module being on', function () {
    $rina = ($this->teacher)('Rina Akter');
    $made = ($this->assign)('MATH', $rina->employee)->assertCreated()->json('data');

    // From another campus of the institution, the section and its rows look unknown.
    $other = createChild($this->w->c1, OrganizationType::Branch, 'North campus');
    ($this->as)()->postJson(($this->api)('structure/subject_teachers', $other), ['section_id' => $this->school->section['id'], 'subject_id' => $this->subjects['BAN1']['id'], 'teacher_id' => $rina->employee])->assertNotFound();
    ($this->as)()->patchJson(($this->api)("structure/subject_teachers/{$made['id']}", $other), ['teacher_id' => $rina->employee, 'base_version' => 1])->assertNotFound();
    expect(($this->as)()->getJson(($this->api)('structure/subject_teachers', $other))->assertOk()->json('data'))->toBe([]);

    // Another company sees nothing of it.
    $stranger = createMember($this->w->c2);
    $this->asToken(orgToken($stranger, $this->w->c2))->getJson(($this->api)('structure/subject_teachers', $this->w->c2))->assertOk()->assertJsonCount(0, 'data');
    $this->asToken(orgToken($stranger, $this->w->c2))->deleteJson(($this->api)("structure/subject_teachers/{$made['id']}", $this->w->c2))->assertNotFound();

    toggles()->disable($this->w->g1, 'education', 'Test', confirm: true);
    ($this->as)()->getJson(($this->api)('structure/subject_teachers'))->assertForbidden();
});
