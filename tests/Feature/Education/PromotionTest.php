<?php

use App\Platform\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Modules\Education\Events\PromotionApplied;
use Modules\Education\Models\Admission;
use Modules\Education\Models\Enrollment;
use Modules\Education\Models\Student;

/*
 * EDU-1b: promotion lists (made for a level or a section, decisions per
 * student, a second person's approval by rule, applied all or nothing,
 * undone within the rule's days while nothing moved on), students from a
 * spreadsheet (checked, then made; duplicates skipped; private columns only
 * with the permission), and applications from won CRM admissions deals.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-20 04:00:00', 'UTC'));
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'education', 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/education/{$path}";
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);

    $this->school = eduSchool($this);
    // The section takes two in 2026; more room next year.
    $levels = collect($this->school->setup['levels']);
    $this->class7 = $levels->first(fn ($level) => $level['program_id'] === $this->school->program['id'] && $level['code'] === 'C7');
    $this->class10 = $levels->first(fn ($level) => $level['program_id'] === $this->school->program['id'] && $level['code'] === 'C10');
    $year = ($this->as)()->postJson(($this->api)('structure/years'), ['name' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'])->assertCreated()->json('data');
    $this->next = ($this->as)()->postJson(($this->api)('structure/sessions'), ['academic_year_id' => $year['id'], 'kind' => 'year', 'name' => ['en' => '2027'], 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'])->assertCreated()->json('data');
    $this->seven = ($this->as)()->postJson(($this->api)('structure/sections'), ['unit_id' => $this->w->b1->id, 'session_id' => $this->next['id'], 'level_id' => $this->class7['id'], 'name' => 'A', 'capacity' => 1])->assertCreated()->json('data');
    $this->sixAgain = ($this->as)()->postJson(($this->api)('structure/sections'), ['unit_id' => $this->w->b1->id, 'session_id' => $this->next['id'], 'level_id' => $this->school->class6['id'], 'name' => 'A'])->assertCreated()->json('data');
    $this->rahim = newStudent($this, $this->school, ['name' => 'Rahim'])->assertCreated()->json('data');
    $this->karim = newStudent($this, $this->school, ['name' => 'Karim', 'guardians' => []])->assertCreated()->json('data');
    $this->list = fn (array $extra = [], ?string $token = null) => ($this->as)($token)->postJson(($this->api)('promotions'), [
        'from_session_id' => $this->school->session['id'], 'to_session_id' => $this->next['id'], 'level_id' => $this->school->class6['id'], ...$extra,
    ]);
    $this->step = fn (array $batch, string $step, array $extra = [], ?string $token = null) => ($this->as)($token)->postJson(($this->api)("promotions/{$batch['id']}/{$step}"), ['base_version' => $batch['version'], ...$extra]);
    $this->line = fn (array $batch, string $name) => collect($batch['lines'])->firstWhere('name', $name);
});

it('promotes, keeps back and lets go, all at once, and undoes it while nothing moved on', function () {
    Event::fake([PromotionApplied::class]);
    $batch = ($this->list)()->assertCreated()->json('data');
    expect($batch)->toMatchArray(['number' => 'PRM-2026-0001', 'status' => 'draft'])
        ->and(collect($batch['lines'])->pluck('decision')->unique()->all())->toBe(['promote'])
        ->and(($this->line)($batch, 'Rahim')['to_level_id'])->toBe($this->class7['id']);

    // A student can be on one open list only.
    ($this->list)()->assertStatus(409)->assertJsonPath('code', 'promotion_open');

    // Leaving needs a reason; Rahim to class 7 A, Karim stays in class 6.
    $lines = fn (array $changes) => ($this->as)()->postJson(($this->api)("promotions/{$batch['id']}/lines"), ['base_version' => $batch['version'], 'lines' => $changes]);
    $lines([['line_id' => ($this->line)($batch, 'Karim')['id'], 'decision' => 'leave']])->assertUnprocessable()->assertJsonValidationErrors('lines.0.reason');
    $batch = $lines([
        ['line_id' => ($this->line)($batch, 'Rahim')['id'], 'decision' => 'promote', 'to_section_id' => $this->seven['id']],
        ['line_id' => ($this->line)($batch, 'Karim')['id'], 'decision' => 'repeat', 'to_section_id' => $this->sixAgain['id'], 'reason' => 'Long illness'],
    ])->assertOk()->json('data');
    // A section of another level or session is refused.
    ($this->as)()->postJson(($this->api)("promotions/{$batch['id']}/lines"), ['base_version' => $batch['version'], 'lines' => [['line_id' => ($this->line)($batch, 'Rahim')['id'], 'decision' => 'promote', 'to_section_id' => $this->sixAgain['id']]]])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.to_section_id');

    $applied = ($this->step)($batch, 'submit')->assertOk()->json('data');
    expect($applied)->toMatchArray(['status' => 'applied', 'undo_until' => '2027-01-19']);
    $rahimNow = Enrollment::where('student_id', $this->rahim['id'])->orderBy('started_on')->get();
    expect($rahimNow->pluck('status')->all())->toBe(['promoted', 'active'])
        ->and($rahimNow[1]->level_id)->toBe($this->class7['id'])
        ->and($rahimNow[1]->section_id)->toBe($this->seven['id'])
        ->and($rahimNow[1]->started_on->toDateString())->toBe('2027-01-01')
        ->and($rahimNow[0]->ended_on->toDateString())->toBe('2026-12-31')
        ->and(Enrollment::where('student_id', $this->karim['id'])->orderBy('started_on')->pluck('status')->all())->toBe(['repeated', 'active']);
    Event::assertDispatchedTimes(PromotionApplied::class, 1);

    // Undone: the new enrollments go, the old ones are open again.
    ($this->step)($applied, 'undo')->assertOk()->assertJsonPath('data.status', 'undone');
    expect(Enrollment::where('student_id', $this->rahim['id'])->pluck('status')->all())->toBe(['active'])
        ->and(Enrollment::count())->toBe(2)
        ->and(AuditLog::whereIn('action', ['education.promotion_applied', 'education.promotion_undone'])->count())->toBe(2);
});

it('undoes nothing too late or once students moved on', function () {
    $batch = ($this->list)(['section_id' => $this->school->section['id']])->assertCreated()->json('data');
    $batch = ($this->as)()->postJson(($this->api)("promotions/{$batch['id']}/lines"), ['base_version' => $batch['version'], 'lines' => [
        ['line_id' => ($this->line)($batch, 'Karim')['id'], 'decision' => 'leave', 'reason' => 'Moved abroad'],
    ]])->assertOk()->json('data');
    $applied = ($this->step)($batch, 'submit')->assertOk()->json('data');
    expect(Student::find($this->karim['id']))->status->toBe('left')->left_reason->toBe('Moved abroad');

    // Karim came back on his own: the list can no longer be undone as a whole.
    Student::whereKey($this->karim['id'])->update(['status' => 'active']);
    ($this->step)($applied, 'undo')->assertStatus(409)->assertJsonPath('code', 'undo_moved_on');

    Student::whereKey($this->karim['id'])->update(['status' => 'left']);
    $this->travelTo(CarbonImmutable::parse('2027-02-01 04:00:00', 'UTC'));
    // Signed in again (the earlier sign-in has expired by then).
    ($this->step)($applied, 'undo', [], orgToken($this->owner, $this->w->c1))->assertStatus(409)->assertJsonPath('code', 'undo_too_late');
});

it('applies all or nothing: a full section stops the whole list', function () {
    $batch = ($this->list)()->assertCreated()->json('data');
    $batch = ($this->as)()->postJson(($this->api)("promotions/{$batch['id']}/lines"), ['base_version' => $batch['version'], 'section_id' => $this->seven['id']])->assertOk()->json('data');
    // Both into a section with room for one.
    expect(collect($batch['lines'])->pluck('to_section_id')->unique()->all())->toBe([$this->seven['id']]);
    ($this->step)($batch, 'submit')->assertStatus(409)->assertJsonPath('code', 'section_full');
    expect(Enrollment::count())->toBe(2)->and(Enrollment::where('status', 'active')->count())->toBe(2);
});

it('waits for a second person when the rule asks, never the maker', function () {
    trustedOrgRule($this->w->c1, 'education.promotion_approval', true);
    $clerk = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view', 'education.promote', 'education.approve_promotion'], 'Clerk'));
    $clerkToken = orgToken($clerk, $this->w->c1);
    $head = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view', 'education.approve_promotion'], 'Head'));
    $headToken = orgToken($head, $this->w->c1);

    $batch = ($this->list)([], $clerkToken)->assertCreated()->json('data');
    $batch = ($this->step)($batch, 'submit', [], $clerkToken)->assertOk()->assertJsonPath('data.status', 'pending_approval')->json('data');
    ($this->step)($batch, 'approve', [], $clerkToken)->assertForbidden()->assertJsonPath('code', 'own_promotion');
    // Approving is not promoting: the head cannot make lists.
    ($this->list)([], $headToken)->assertForbidden();

    $batch = ($this->step)($batch, 'reject', ['note' => 'Check Karim'], $headToken)->assertOk()->assertJsonPath('data.status', 'draft')->json('data');
    $batch = ($this->step)($batch, 'submit', [], $clerkToken)->assertOk()->json('data');
    ($this->step)($batch, 'approve', [], $headToken)->assertOk()->assertJsonPath('data.status', 'applied')->assertJsonPath('data.approved_by', $head->id);

    // Unknown steps look unknown.
    ($this->step)($batch, 'explode')->assertNotFound();
});

it('graduates on the last level and only there', function () {
    $batch = ($this->list)()->assertCreated()->json('data');
    ($this->as)()->postJson(($this->api)("promotions/{$batch['id']}/lines"), ['base_version' => $batch['version'], 'lines' => [['line_id' => $batch['lines'][0]['id'], 'decision' => 'graduate']]])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.decision');
    ($this->step)($batch, 'cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');

    // Nobody studies class 10: nothing to promote.
    ($this->list)(['level_id' => $this->class10['id']])->assertUnprocessable()->assertJsonPath('code', 'nothing_to_promote');
    // The next session must come after this one.
    ($this->list)(['to_session_id' => $this->school->session['id']])->assertUnprocessable()->assertJsonPath('code', 'mismatch_to_session_id');
});

it('stops a list whose students changed since it was made', function () {
    $batch = ($this->list)()->assertCreated()->json('data');
    $karim = ($this->as)()->getJson(($this->api)("students/{$this->karim['id']}"))->json('data');
    ($this->as)()->postJson(($this->api)("students/{$this->karim['id']}/leave"), ['base_version' => $karim['version'], 'status' => 'left', 'reason' => 'Family moved'])->assertOk();

    ($this->step)($batch, 'submit')->assertStatus(409)->assertJsonPath('code', 'changed_since');
});

// ── Spreadsheet ──

it('checks a spreadsheet first, then makes the good rows once', function () {
    $sectionB = ($this->as)()->postJson(($this->api)('structure/sections'), ['unit_id' => $this->w->b1->id, 'session_id' => $this->school->session['id'], 'level_id' => $this->school->class6['id'], 'name' => 'B'])->json('data');
    $rows = [
        ['name' => 'Nadia', 'gender' => 'female', 'section' => 'B', 'guardian_name' => 'Salma', 'guardian_phone' => '01811-111111', 'guardian_relation' => 'mother', 'blood_group' => 'O+'],
        ['name' => 'Nadia', 'gender' => 'female', 'section' => 'B', 'guardian_name' => 'Salma', 'guardian_phone' => '01811-111111', 'guardian_relation' => 'mother'],
        ['name' => '', 'section' => 'Z'],
        ['name' => 'Tariq', 'section' => 'b', 'phone' => '12'],
        ['name' => 'Omar', 'section' => 'B', 'admitted_on' => '05/01/2026'],
    ];
    $body = ['unit_id' => $this->w->b1->id, 'program_id' => $this->school->program['id'], 'session_id' => $this->school->session['id'], 'level_id' => $this->school->class6['id'], 'rows' => $rows];

    $check = ($this->as)()->postJson(($this->api)('students/import'), [...$body, 'commit' => false])->assertOk()->json('data');
    expect($check)->toMatchArray(['ok' => 2, 'duplicates' => 1, 'errors' => 2, 'made' => 0])
        ->and($check['rows'][2]['errors'])->toHaveKeys(['name', 'section'])
        ->and($check['rows'][3]['errors'])->toHaveKey('phone')
        ->and(Student::count())->toBe(2);

    $made = ($this->as)()->postJson(($this->api)('students/import'), [...$body, 'commit' => true])->assertOk()->json('data');
    expect($made['made'])->toBe(2)
        ->and(Student::where('name', 'Omar')->sole()->admitted_on->toDateString())->toBe('2026-01-05')
        ->and(Enrollment::where('section_id', $sectionB['id'])->count())->toBe(2)
        ->and(Student::where('name', 'Nadia')->sole()->extra)->toBe(['blood_group' => 'O+']);
    // The same file again makes nothing twice.
    ($this->as)()->postJson(($this->api)('students/import'), [...$body, 'commit' => true])->assertOk();
    expect(Student::count())->toBe(4);

    // Private columns need the permission.
    $clerk = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view', 'education.admit'], 'Clerk'));
    $this->asToken(orgToken($clerk, $this->w->c1))->postJson(($this->api)('students/import'), [...$body, 'commit' => false, 'rows' => [['name' => 'X', 'date_of_birth' => '2014-01-01']]])
        ->assertForbidden();
});

// ── From customer relations ──

it('makes an application from a won admissions deal, once, to be placed before admitting', function () {
    toggles()->enable($this->w->g1, 'crm', 'Test setup');
    $crm = fn (string $path) => '/api/organizations/'.$this->w->b1->id."/crm/{$path}";
    $setup = ($this->as)()->getJson($crm('setup'))->assertOk()->json('data');
    $pipeline = collect($setup['pipelines'])->firstWhere('key', 'admissions');
    $won = collect($pipeline['stages'])->firstWhere('outcome', 'won')['id'];
    $contact = ($this->as)()->postJson($crm('contacts'), ['name' => 'Mrs Begum', 'phone' => '01911-222222'])->assertCreated()->json('data.id');
    $deal = ($this->as)()->postJson($crm('deals'), ['contact_id' => $contact, 'title' => 'Class 6 for her daughter', 'pipeline_id' => $pipeline['id']])->assertCreated()->json('data');

    $deal = ($this->as)()->postJson($crm("deals/{$deal['id']}/move"), ['base_version' => $deal['version'], 'stage_id' => $won])->assertOk()->json('data');
    $application = Admission::sole();
    expect($application)->source->toBe('crm')->source_ref->toBe($deal['id'])->program_id->toBeNull()->unit_id->toBe($this->w->b1->id)
        ->and($application->applicant['guardians'][0])->toMatchArray(['name' => 'Mrs Begum', 'phone' => '+8801911222222']);

    // Admitting needs the place applied for.
    ($this->as)()->postJson(($this->api)("admissions/{$application->id}/admit"), ['base_version' => 1])->assertUnprocessable()->assertJsonPath('code', 'not_placed');
    ($this->as)()->patchJson(($this->api)("admissions/{$application->id}"), [
        'base_version' => 1, 'program_id' => $this->school->program['id'], 'level_id' => $this->school->class6['id'], 'session_id' => $this->school->session['id'],
        'applicant' => ['name' => 'Ayesha'],
    ])->assertOk()->assertJsonPath('data.applicant.name', 'Ayesha');

    // Won again (reopened, then won) makes no second application.
    $open = collect($pipeline['stages'])->firstWhere('outcome', 'open')['id'];
    $deal = ($this->as)()->postJson($crm("deals/{$deal['id']}/move"), ['base_version' => $deal['version'], 'stage_id' => $open])->json('data');
    ($this->as)()->postJson($crm("deals/{$deal['id']}/move"), ['base_version' => $deal['version'], 'stage_id' => $won])->assertOk();
    expect(Admission::count())->toBe(1);

    // Not an admissions pipeline: nothing.
    orgRule($this->w->c1, 'education.crm_admission_pipelines', ['enquiries']);
    $other = ($this->as)()->postJson($crm('deals'), ['contact_id' => $contact, 'title' => 'Another', 'pipeline_id' => $pipeline['id']])->json('data');
    ($this->as)()->postJson($crm("deals/{$other['id']}/move"), ['base_version' => $other['version'], 'stage_id' => $won])->assertOk();
    expect(Admission::count())->toBe(1);
});
