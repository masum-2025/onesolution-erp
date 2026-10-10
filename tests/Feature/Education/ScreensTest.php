<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * EDU-2a: what the education screens read beyond EDU-1: the overview of a
 * session (sections with seats taken, numbers that need attention), the
 * set-up with own fields and batches (private fields only for people allowed
 * to see them), and the students list filtered to those without a section.
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

it('shows the open session with its sections, seats taken and what needs attention', function () {
    $school = eduSchool($this);
    newStudent($this, $school)->assertCreated();
    newStudent($this, $school, ['name' => 'Karim', 'guardians' => [], 'enrollment' => ['session_id' => $school->session['id'], 'level_id' => $school->class6['id']]])->assertCreated();

    $data = ($this->as)()->getJson(($this->api)('overview'))->assertOk()->json('data');
    expect($data['session_id'])->toBe($school->session['id'])
        ->and($data['sections'])->toHaveCount(1)
        ->and($data['sections'][0])->toMatchArray(['id' => $school->section['id'], 'taken' => 1, 'capacity' => 2])
        ->and($data['stats'])->toMatchArray(['students' => 2, 'unplaced' => 1, 'sections' => 1, 'nearly_full' => 0, 'applications' => 0, 'promotions_waiting' => 0]);

    // One more fills section A (2 of 2): nearly full.
    newStudent($this, $school, ['name' => 'Third', 'guardians' => []])->assertCreated();
    ($this->as)()->getJson(($this->api)("overview?session_id={$school->session['id']}"))->assertOk()->assertJsonPath('data.stats.nearly_full', 1);

    // An unknown session: nothing, not an error.
    ($this->as)()->getJson(($this->api)('overview?session_id='.str_repeat('0', 26)))->assertOk()->assertJsonPath('data.session_id', null)->assertJsonPath('data.sections', []);
    ($this->as)()->getJson(($this->api)('overview?session_id=x'))->assertUnprocessable();
});

it('hides waiting work from people who cannot act on it, and answers 403 while Education is off', function () {
    eduSchool($this);
    $looker = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view'], 'Looker'));
    $token = orgToken($looker, $this->w->c1);
    $this->asToken($token)->getJson(($this->api)('overview'))->assertOk()
        ->assertJsonPath('data.stats.applications', null)->assertJsonPath('data.stats.promotions_waiting', null);

    $nobody = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['crm.view'], 'Other'));
    $this->asToken(orgToken($nobody, $this->w->c1))->getJson(($this->api)('overview'))->assertForbidden();

    toggles()->disable($this->w->g1, 'education', 'Test', confirm: true);
    ($this->as)()->getJson(($this->api)('overview'))->assertForbidden();
});

it('shows a teacher only their own sections in the overview', function () {
    $school = eduSchool($this);
    ($this->as)()->postJson(($this->api)('structure/sections'), ['unit_id' => $this->w->b1->id, 'session_id' => $school->session['id'], 'level_id' => $school->class6['id'], 'name' => 'B'])->assertCreated();
    $teacher = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view'], 'Teacher'));
    $token = orgToken($teacher, $this->w->c1);
    $this->asToken($token)->getJson(($this->api)('overview'))->assertOk()->assertJsonPath('data.sections', [])->assertJsonPath('data.stats.students', 0);

    toggles()->enable($this->w->g1, 'hrm', 'Test setup');
    $employee = hireVia($this, $this->token, $this->w->c1, ['full_name' => 'Teacher One'])->assertCreated()->json('data.id');
    DB::table('hrm_employees')->where('id', $employee)->update(['user_id' => $teacher->id]);
    $section = ($this->as)()->getJson(($this->api)("structure/sections?session_id={$school->session['id']}"))->json('data.0');
    ($this->as)()->patchJson(($this->api)("structure/sections/{$school->section['id']}"), ['base_version' => $section['version'], 'class_teacher_id' => $employee])->assertOk();

    $sections = $this->asToken($token)->getJson(($this->api)('overview'))->assertOk()->json('data.sections');
    expect(collect($sections)->pluck('id')->all())->toBe([$school->section['id']]);
});

it('keeps the overview of another institution or partner out of reach', function () {
    eduSchool($this);
    $c4 = createMember($this->w->c4);
    $this->asToken(orgToken($c4, $this->w->c4))->getJson(($this->api)('overview', $this->w->c1))->assertNotFound();
    $c2 = createMember($this->w->c2);
    $this->asToken(orgToken($c2, $this->w->c2))->getJson(($this->api)('overview', $this->w->c2))->assertOk()
        ->assertJsonPath('data.sections', [])->assertJsonPath('data.stats.students', 0);
});

it('gives the screens own fields and batches, private fields only to people allowed to see them', function () {
    $school = eduSchool($this);
    ($this->as)()->postJson(($this->api)('structure/batches'), ['program_id' => $school->program['id'], 'name' => 'Batch 2026'])->assertCreated();

    $setup = ($this->as)()->getJson(($this->api)('setup'))->assertOk()->json('data');
    $keys = collect($setup['fields']['student'])->pluck('key');
    expect($setup['batches'])->toHaveCount(1)->and($keys)->toContain('religion')
        ->and(array_keys($setup['fields']))->toBe(['student', 'guardian', 'admission']);

    $clerk = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view', 'education.admit'], 'Clerk'));
    $clerkSetup = $this->asToken(orgToken($clerk, $this->w->c1))->getJson(($this->api)('setup'))->assertOk()->json('data');
    expect(collect($clerkSetup['fields']['student'])->pluck('key'))->not->toContain('religion')
        ->and(collect($clerkSetup['fields']['student'])->every(fn ($field) => $field['is_sensitive'] === false))->toBeTrue();
});

it('lists the students who are in no section yet', function () {
    $school = eduSchool($this);
    newStudent($this, $school)->assertCreated();
    $waiting = newStudent($this, $school, ['name' => 'Waiting', 'guardians' => [], 'enrollment' => ['session_id' => $school->session['id'], 'level_id' => $school->class6['id']]])->json('data');
    newStudent($this, $school, ['name' => 'Nowhere', 'guardians' => [], 'enrollment' => null])->assertCreated();

    ($this->as)()->getJson(($this->api)('students?unplaced=1'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $waiting['id']);
    ($this->as)()->getJson(($this->api)("students?unplaced=1&session_id={$school->session['id']}"))->assertOk()->assertJsonPath('meta.total', 1);
    ($this->as)()->getJson(($this->api)('students'))->assertOk()->assertJsonPath('meta.total', 3);
    ($this->as)()->getJson(($this->api)('students?unplaced=maybe'))->assertUnprocessable();
});
