<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\CourseRegistration\Models\Registration;
use Modules\CourseRegistration\Models\RegistrationItem;
use Modules\EducationExams\Events\MarksCorrected;
use Modules\EducationExams\Events\ResultPublished;

/*
 * EXM-2: results. Worked out from approved marks (grades, GPA, fail on a
 * subject, merit positions), published once every sheet is approved,
 * corrected after publishing by someone else, combined over exams with
 * weights, a student's transcript, and course registration outcomes.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'education', 'Test setup');
        toggles()->enable($group, 'education_exams', 'Test setup');
    }
    orgRule($this->w->c1, 'education_exams.moderation_required', false);
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->checker = orgToken(createMember($this->w->c1), $this->w->c1);
    $this->api = fn (string $path) => "/api/organizations/{$this->w->c1->id}/education/{$path}";
    $this->exams = fn (string $path = '') => "/api/organizations/{$this->w->c1->id}/exams".($path === '' ? '' : "/{$path}");
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);

    $this->school = eduSchool($this);
    $this->subjects = collect(($this->as)()->getJson(($this->api)('structure/subjects'))->json('data'))->keyBy('code');
    $this->rahim = newStudent($this, $this->school, ['name' => 'Rahim', 'guardians' => []])->assertCreated()->json('data');
    $this->karim = newStudent($this, $this->school, ['name' => 'Karim', 'guardians' => []])->assertCreated()->json('data');
    $this->gpa5 = collect(($this->as)()->getJson(($this->exams)('setup'))->json('data.schemes'))->firstWhere('code', 'bd_school_gpa5');

    // An exam of BAN1, ENG1, MATH (100 each, pass 33) with whole marks given per student, closed (and published when asked).
    $this->run = function (string $kind, array $marks, bool $publish = true) {
        $exam = ($this->as)()->postJson(($this->exams)(), ['name' => ['en' => ucfirst($kind)], 'session_id' => $this->school->session['id'], 'kind' => $kind, 'grading_scheme_id' => $this->gpa5['id']])->json('data');
        foreach (['BAN1', 'ENG1', 'MATH'] as $code) {
            ($this->as)()->postJson(($this->exams)("{$exam['id']}/papers"), ['level_id' => $this->school->class6['id'], 'subject_id' => $this->subjects[$code]['id'], 'full_marks_centi' => 10000, 'pass_marks_centi' => 3300])->assertCreated();
        }
        $exam = ($this->as)()->postJson(($this->exams)("{$exam['id']}/status"), ['status' => 'marking', 'base_version' => $exam['version']])->json('data');
        $papers = collect($exam['papers'])->keyBy('subject.code');
        foreach (($this->as)()->getJson(($this->exams)("{$exam['id']}/sheets"))->json('data') as $sheet) {
            $code = $papers->firstWhere('id', $sheet['paper_id'])['subject']['code'];
            $part = $papers[$code]['parts'][0]['id'];
            $rows = [];
            foreach ($marks as $studentId => $byCode) {
                $rows[] = ['student_id' => $studentId, 'part_id' => $part, 'marks_centi' => $byCode[$code] * 100];
            }
            $saved = ($this->as)()->putJson(($this->exams)("sheets/{$sheet['id']}/marks"), ['marks' => $rows])->assertOk()->json('data');
            ($this->as)()->postJson(($this->exams)("sheets/{$sheet['id']}/submit"), ['base_version' => $saved['version']])->assertOk();
        }
        $exam = ($this->as)()->postJson(($this->exams)("{$exam['id']}/status"), ['status' => 'closed', 'base_version' => $exam['version']])->assertOk()->json('data');
        if ($publish) {
            $exam = ($this->as)()->postJson(($this->exams)("{$exam['id']}/publish"), ['base_version' => $exam['version']])->assertOk()->json('data');
        }

        return [$exam, $papers];
    };
});

it('works out grades, GPA and positions, then publishes once every sheet is approved', function () {
    Event::fake([ResultPublished::class]);
    [$exam] = ($this->run)('term', [$this->rahim['id'] => ['BAN1' => 85, 'ENG1' => 72, 'MATH' => 64], $this->karim['id'] => ['BAN1' => 90, 'ENG1' => 20, 'MATH' => 50]], publish: false);

    $preview = ($this->as)()->postJson(($this->exams)("{$exam['id']}/results/compute"))->assertOk()->json();
    $rahim = collect($preview['data'])->firstWhere('student_id', $this->rahim['id']);
    $karim = collect($preview['data'])->firstWhere('student_id', $this->karim['id']);
    // (5 + 4 + 3.5) / 3 = 4.17 -> A; Karim fails English: F, GPA 0, still placed after Rahim.
    expect($rahim)->toMatchArray(['gpa_centi' => 417, 'letter' => 'A', 'passed' => true, 'rank' => 1, 'marks_centi' => 22100, 'status' => 'draft'])
        ->and(collect($rahim['subjects'])->pluck('letter')->sort()->values()->all())->toBe(['A', 'A+', 'A-'])
        ->and($karim)->toMatchArray(['gpa_centi' => 0, 'letter' => 'F', 'passed' => false, 'failed_subjects' => 1, 'rank' => 2])
        ->and($preview['meta'])->toMatchArray(['students' => 2, 'passed' => 1]);

    $published = ($this->as)()->postJson(($this->exams)("{$exam['id']}/publish"), ['base_version' => $exam['version']])->assertOk()->json('data');
    expect($published['status'])->toBe('published');
    Event::assertDispatched(ResultPublished::class, fn ($event) => $event->sourceType === 'exam' && $event->sourceId === $exam['id']);
    expect(($this->as)()->getJson(($this->exams)("{$exam['id']}/results"))->json('data.0.status'))->toBe('published');
    // Published marks stay; positions hidden when the rule says so.
    orgRule($this->w->c1, 'education_exams.show_rank', false);
    expect(($this->as)()->getJson(($this->exams)("{$exam['id']}/results"))->json('data.0.rank'))->toBeNull();
});

it('refuses to publish while a sheet waits for approval', function () {
    orgRule($this->w->c1, 'education_exams.moderation_required', true);
    [$exam] = ($this->run)('term', [$this->rahim['id'] => ['BAN1' => 50, 'ENG1' => 50, 'MATH' => 50], $this->karim['id'] => ['BAN1' => 50, 'ENG1' => 50, 'MATH' => 50]], publish: false);
    ($this->as)()->postJson(($this->exams)("{$exam['id']}/publish"), ['base_version' => $exam['version']])->assertStatus(409)->assertJsonPath('code', 'sheets_not_approved');
});

it('corrects a published mark with someone else\'s approval and works the results out again', function () {
    Event::fake([MarksCorrected::class, ResultPublished::class]);
    [$exam, $papers] = ($this->run)('term', [$this->rahim['id'] => ['BAN1' => 85, 'ENG1' => 72, 'MATH' => 64], $this->karim['id'] => ['BAN1' => 90, 'ENG1' => 20, 'MATH' => 50]]);
    $sheet = collect(($this->as)()->getJson(($this->exams)("{$exam['id']}/sheets"))->json('data'))->firstWhere('paper_id', $papers['ENG1']['id']);

    $asked = ($this->as)()->postJson(($this->exams)("sheets/{$sheet['id']}/corrections"), ['student_id' => $this->karim['id'], 'part_id' => $papers['ENG1']['parts'][0]['id'], 'marks_centi' => 4000, 'reason' => 'Page 3 was not counted'])
        ->assertCreated()->json('data');
    expect($asked['status'])->toBe('pending');
    ($this->as)()->postJson(($this->exams)("corrections/{$asked['id']}/approve"), ['base_version' => $asked['version']])->assertForbidden()->assertJsonPath('code', 'own_approval');
    ($this->as)($this->checker)->postJson(($this->exams)("corrections/{$asked['id']}/approve"), ['base_version' => $asked['version']])->assertOk()->assertJsonPath('data.status', 'applied');

    // 90 A+ (5) + 40 C (2) + 50 B (3) = 3.33 -> B; still published.
    $karim = collect(($this->as)()->getJson(($this->exams)("{$exam['id']}/results"))->json('data'))->firstWhere('student_id', $this->karim['id']);
    expect($karim)->toMatchArray(['passed' => true, 'gpa_centi' => 333, 'letter' => 'B', 'status' => 'published']);
    Event::assertDispatched(MarksCorrected::class);
    expect(($this->as)()->getJson(($this->exams)("{$exam['id']}/corrections"))->json('data.0'))->toMatchArray(['old_marks_centi' => 2000, 'new_marks_centi' => 4000, 'status' => 'applied']);
});

it('combines exams by weight, publishes the final result and keeps a transcript', function () {
    [$term] = ($this->run)('term', [$this->rahim['id'] => ['BAN1' => 60, 'ENG1' => 60, 'MATH' => 60], $this->karim['id'] => ['BAN1' => 40, 'ENG1' => 40, 'MATH' => 40]]);
    [$final] = ($this->run)('final', [$this->rahim['id'] => ['BAN1' => 90, 'ENG1' => 80, 'MATH' => 70], $this->karim['id'] => ['BAN1' => 50, 'ENG1' => 50, 'MATH' => 50]]);

    ($this->as)()->postJson(($this->exams)('combinations'), ['name' => ['en' => 'Annual 2026'], 'session_id' => $this->school->session['id'], 'level_id' => $this->school->class6['id'],
        'grading_scheme_id' => $this->gpa5['id'], 'exams' => [['exam_id' => $term['id'], 'weight_bp' => 3000], ['exam_id' => $final['id'], 'weight_bp' => 6000]]])
        ->assertUnprocessable()->assertJsonPath('code', 'weights_not_whole');
    $combination = ($this->as)()->postJson(($this->exams)('combinations'), ['name' => ['en' => 'Annual 2026'], 'session_id' => $this->school->session['id'], 'level_id' => $this->school->class6['id'],
        'grading_scheme_id' => $this->gpa5['id'], 'exams' => [['exam_id' => $term['id'], 'weight_bp' => 3000], ['exam_id' => $final['id'], 'weight_bp' => 7000]]])->assertCreated()->json('data');

    // Rahim: BAN1 0.3×60 + 0.7×90 = 81 A+, ENG1 74 A, MATH 67 A- -> (5 + 4 + 3.5) / 3 = 4.17.
    $results = ($this->as)()->getJson(($this->exams)("combinations/{$combination['id']}/results"))->assertOk()->json('data');
    expect(collect($results)->firstWhere('student_id', $this->rahim['id']))->toMatchArray(['gpa_centi' => 417, 'letter' => 'A', 'status' => 'draft']);
    ($this->as)()->postJson(($this->exams)("combinations/{$combination['id']}/publish"), ['base_version' => $combination['version']])->assertOk()->assertJsonPath('data.status', 'published');

    $transcript = ($this->as)()->getJson(($this->exams)("students/{$this->rahim['id']}/results"))->assertOk()->json('data');
    expect($transcript['results'])->toHaveCount(3)->and($transcript['cgpa_centi'])->toBe(417);
});

it('sets course registration outcomes from published results', function () {
    toggles()->enable($this->w->g1, 'course_registration', 'Test setup');
    $registration = new Registration;
    $registration->forceFill(['organization_id' => $this->w->c1->id, 'unit_id' => $this->w->b1->id, 'session_id' => $this->school->session['id'], 'student_id' => $this->karim['id'], 'status' => 'approved', 'credits_centi' => 600, 'version' => 1])->save();
    foreach (['BAN1', 'ENG1'] as $code) {
        (new RegistrationItem)->forceFill(['organization_id' => $this->w->c1->id, 'registration_id' => $registration->id, 'student_id' => $this->karim['id'], 'session_id' => $this->school->session['id'],
            'offering_id' => (string) Str::ulid(), 'subject_id' => $this->subjects[$code]['id'], 'credits_centi' => 300, 'status' => 'registered', 'source' => 'staff'])->save();
    }
    ($this->run)('final', [$this->rahim['id'] => ['BAN1' => 50, 'ENG1' => 50, 'MATH' => 50], $this->karim['id'] => ['BAN1' => 90, 'ENG1' => 20, 'MATH' => 50]]);

    expect(RegistrationItem::query()->withoutGlobalScopes()->get()->pluck('outcome', 'subject_id')->all())
        ->toBe([$this->subjects['BAN1']['id'] => 'completed', $this->subjects['ENG1']['id'] => 'failed']);
});
