<?php

use App\Models\User;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Modules\EducationExams\Models\Notice;
use Modules\EducationExams\Services\ResultNotices;

/*
 * EXM-4: results for families. Report cards to print (what the reader may
 * see, who signs by rule); published results told to families by SMS or
 * mail (rule, off by default; once, then again once a day from the office);
 * a family's results in the portal (published only, their own child, held
 * while fees are owed when the rule says so).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    platformRule('notifications.sms_enabled', true);
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        foreach (['education', 'education_exams', 'client_portal'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    orgRule($this->w->c1, 'education_exams.moderation_required', false);
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->api = fn (string $path) => "/api/organizations/{$this->w->c1->id}/education/{$path}";
    $this->exams = fn (string $path = '', $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id.'/exams'.($path === '' ? '' : "/{$path}");
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);

    $this->school = eduSchool($this);
    $this->subjects = collect(($this->as)()->getJson(($this->api)('structure/subjects'))->json('data'))->keyBy('code');
    $this->rahim = newStudent($this, $this->school, ['name' => 'Rahim', 'guardians' => [['name' => 'Abdul', 'phone' => '01711-000001', 'relation' => 'father']]])->assertCreated()->json('data');
    $this->karim = newStudent($this, $this->school, ['name' => 'Karim', 'guardians' => []])->assertCreated()->json('data');
    $this->gpa5 = collect(($this->as)()->getJson(($this->exams)('setup'))->json('data.schemes'))->firstWhere('code', 'bd_school_gpa5');

    // An exam of BAN1 and MATH (Maths split into creative 70 + MCQ 30), marks given, closed, and published when asked.
    $this->run = function (bool $publish = true) {
        $exam = ($this->as)()->postJson(($this->exams)(), ['name' => ['en' => 'Half-yearly', 'bn' => 'অর্ধবার্ষিক'], 'session_id' => $this->school->session['id'], 'kind' => 'term', 'grading_scheme_id' => $this->gpa5['id']])->json('data');
        ($this->as)()->postJson(($this->exams)("{$exam['id']}/papers"), ['level_id' => $this->school->class6['id'], 'subject_id' => $this->subjects['BAN1']['id'], 'full_marks_centi' => 10000, 'pass_marks_centi' => 3300])->assertCreated();
        ($this->as)()->postJson(($this->exams)("{$exam['id']}/papers"), ['level_id' => $this->school->class6['id'], 'subject_id' => $this->subjects['MATH']['id'], 'full_marks_centi' => 10000, 'pass_marks_centi' => 3300,
            'parts' => [['code' => 'CQ', 'full_marks_centi' => 7000, 'pass_marks_centi' => 2300], ['code' => 'MCQ', 'full_marks_centi' => 3000, 'pass_marks_centi' => 1000]]])->assertCreated();
        $exam = ($this->as)()->postJson(($this->exams)("{$exam['id']}/status"), ['status' => 'marking', 'base_version' => $exam['version']])->json('data');
        $marks = [$this->rahim['id'] => [8500, 5800, 2400], $this->karim['id'] => [6000, 3000, 1500]];
        foreach (($this->as)()->getJson(($this->exams)("{$exam['id']}/sheets"))->json('data') as $sheet) {
            $view = ($this->as)()->getJson(($this->exams)("sheets/{$sheet['id']}"))->json('data');
            $rows = [];
            foreach ($marks as $studentId => $byPart) {
                foreach ($view['parts'] as $index => $part) {
                    $rows[] = ['student_id' => $studentId, 'part_id' => $part['id'], 'marks_centi' => count($view['parts']) === 1 ? $byPart[0] : $byPart[$index + 1]];
                }
            }
            $saved = ($this->as)()->putJson(($this->exams)("sheets/{$sheet['id']}/marks"), ['marks' => $rows])->assertOk()->json('data');
            ($this->as)()->postJson(($this->exams)("sheets/{$sheet['id']}/submit"), ['base_version' => $saved['version']])->assertOk();
        }
        $exam = ($this->as)()->postJson(($this->exams)("{$exam['id']}/status"), ['status' => 'closed', 'base_version' => $exam['version']])->assertOk()->json('data');
        ($this->as)()->postJson(($this->exams)("{$exam['id']}/results/compute"))->assertOk();
        if ($publish) {
            $exam = ($this->as)(orgToken(createMember($this->w->c1), $this->w->c1))->postJson(($this->exams)("{$exam['id']}/publish"), ['base_version' => $exam['version']])->assertOk()->json('data');
        }

        return $exam;
    };
    // A parent in the portal linked to a student.
    $this->parent = function (string $studentId) {
        $parent = User::factory()->create(['email_verified_at' => now()]);
        app(AddMember::class)->handle($this->w->c1, $parent, MembershipType::Portal, AccessScope::Own);
        $membership = OrganizationMembership::query()->where('user_id', $parent->id)->where('organization_id', $this->w->c1->id)->sole();
        $link = new PortalLink;
        $link->forceFill([
            'organization_id' => $this->w->c1->id, 'membership_id' => $membership->id, 'user_id' => $parent->id,
            'subject_type' => 'education.student', 'subject_id' => $studentId, 'relation' => 'mother', 'status' => PortalLink::ACTIVE, 'linked_via' => 'invitation',
        ])->save();

        return (object) ['token' => orgToken($parent, $this->w->c1), 'record' => $link->id];
    };
    $this->sms = fn (string $key) => NotificationDelivery::query()->where('notification_key', $key)->where('channel', 'sms')->get();
});

it('gives report cards of a section with subjects, parts, signatures by rule, to those who may see them', function () {
    $exam = ($this->run)();
    $cards = ($this->as)()->getJson(($this->exams)("{$exam['id']}/report-cards?section_id={$this->school->section['id']}"))->assertOk()->json('data');
    $rahim = collect($cards['students'])->firstWhere('student.id', $this->rahim['id']);
    $maths = collect($rahim['subjects'])->firstWhere('subject.code', 'MATH');
    expect($cards['students'])->toHaveCount(2)
        ->and($cards['source'])->toMatchArray(['type' => 'exam', 'status' => 'published'])
        ->and($cards['signatures'])->toBe(['class_teacher', 'head', 'guardian'])
        ->and($cards['scheme']['bands'])->toHaveCount(7)
        ->and($rahim)->toMatchArray(['rank' => 1, 'letter' => 'A+', 'section' => $this->school->section['name'], 'attendance' => null])
        ->and(collect($maths['parts'])->pluck('code')->all())->toBe(['CQ', 'MCQ'])
        ->and(collect($maths['parts'])->pluck('marks_centi')->all())->toBe([5800, 2400])
        ->and(collect($rahim['subjects'])->firstWhere('subject.code', 'BAN1')['parts'])->toBe([]);

    // One student; who signs and positions follow the rules.
    orgRule($this->w->c1, 'education_exams.marksheet_signatures', ['head']);
    orgRule($this->w->c1, 'education_exams.show_rank', false);
    $one = ($this->as)()->getJson(($this->exams)("{$exam['id']}/report-cards?student_id={$this->karim['id']}"))->json('data');
    expect($one['students'])->toHaveCount(1)->and($one['signatures'])->toBe(['head'])->and($one['students'][0]['rank'])->toBeNull();

    // Someone who teaches nothing gets no student; another institution, nothing; exams off, 403.
    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education_exams.view'], 'Viewer')), $this->w->c1);
    expect(($this->as)($viewer)->getJson(($this->exams)("{$exam['id']}/report-cards"))->json('data.students'))->toBe([]);
    $stranger = orgToken(createMember($this->w->c2), $this->w->c2);
    $this->asToken($stranger)->getJson(($this->exams)("{$exam['id']}/report-cards", $this->w->c2))->assertNotFound();
    toggles()->disable($this->w->g1, 'education_exams', 'Test');
    ($this->as)()->getJson(($this->exams)("{$exam['id']}/report-cards"))->assertForbidden();
});

it('tells families published results only when the rule is on, once, and again once a day from the office', function () {
    // Off by default: nothing goes.
    ($this->run)();
    expect(NotificationDelivery::query()->count())->toBe(0);

    orgRule($this->w->c1, 'education_exams.notify_results', ['enabled' => true, 'channels' => ['sms']]);
    $exam = ($this->run)();
    // Rahim's father (no login) by SMS on the phone kept on the record; Karim has no contact.
    $sent = ($this->sms)('education_exams.result_published');
    expect($sent)->toHaveCount(1)->and(Notice::query()->withoutGlobalScopes()->where('kind', 'published')->count())->toBe(1);

    $again = ($this->as)()->postJson(($this->exams)("{$exam['id']}/send-results"))->assertOk()->json('data');
    expect($again)->toBe(['sent' => 1, 'already' => 0, 'no_contact' => 1]);
    expect(($this->as)()->postJson(($this->exams)("{$exam['id']}/send-results"))->json('data'))->toBe(['sent' => 0, 'already' => 1, 'no_contact' => 1])
        ->and(($this->sms)('education_exams.result_published'))->toHaveCount(2);

    // Only published results, only with .publish.
    $draft = ($this->run)(publish: false);
    ($this->as)()->postJson(($this->exams)("{$draft['id']}/send-results"))->assertStatus(409)->assertJsonPath('code', 'wrong_status');
    $teacher = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education_exams.view', 'education_exams.manage'], 'Office')), $this->w->c1);
    ($this->as)($teacher)->postJson(($this->exams)("{$exam['id']}/send-results"))->assertForbidden();
    expect(ResultNotices::point(417, 'bn'))->toBe('৪.১৭')->and(ResultNotices::point(500, 'en'))->toBe('5.00');
});

it('shows a family their own child\'s published results in the portal, and nothing else', function () {
    $draft = ($this->run)(publish: false);
    $parent = ($this->parent)($this->rahim['id']);
    $view = $this->asToken($parent->token)->getJson("/api/portal/results?record={$parent->record}")->assertOk()->json('data');
    expect($view['results'])->toBe([])->and($view['held'])->toBeFalse()->and($view['student']['name'])->toBe('Rahim');

    $exam = ($this->run)();
    $view = $this->asToken($parent->token)->getJson("/api/portal/results?record={$parent->record}")->assertOk()->json('data');
    expect($view['results'])->toHaveCount(1)->and($view['results'][0]['gpa_centi'])->toBe(500)->and($view['results'][0]['rank'])->toBe(1);
    $card = $this->asToken($parent->token)->getJson("/api/portal/results/{$view['results'][0]['id']}/report-card?record={$parent->record}")->assertOk()->json('data');
    expect($card['students'])->toHaveCount(1)->and($card['students'][0]['student']['id'])->toBe($this->rahim['id']);

    // Karim's result, a draft, another record: the same 404; staff are not portal members.
    $karimResult = collect(($this->as)()->getJson(($this->exams)("{$exam['id']}/results"))->json('data'))->firstWhere('student_id', $this->karim['id']);
    $this->asToken($parent->token)->getJson("/api/portal/results/{$karimResult['id']}/report-card?record={$parent->record}")->assertNotFound();
    $draftResult = collect(($this->as)()->getJson(($this->exams)("{$draft['id']}/results"))->json('data'))->firstWhere('student_id', $this->rahim['id']);
    $this->asToken($parent->token)->getJson("/api/portal/results/{$draftResult['id']}/report-card?record={$parent->record}")->assertNotFound();
    $other = ($this->parent)($this->karim['id']);
    $this->asToken($parent->token)->getJson("/api/portal/results?record={$other->record}")->assertNotFound();
    ($this->as)()->getJson("/api/portal/results?record={$parent->record}")->assertNotFound();
});

it('holds results of a student who owes fees when the rule says so, and tells the family to contact the office', function () {
    toggles()->enable($this->w->g1, 'education_fees', 'Test setup');
    $fees = fn (string $path) => "/api/organizations/{$this->w->c1->id}/fees/{$path}";
    $tuition = ($this->as)()->postJson($fees('heads'), ['code' => 'TUI', 'name' => ['en' => 'Tuition'], 'frequency' => 'monthly', 'income_key' => 'education_fees.tuition_income'])->json('data');
    $structure = ($this->as)()->postJson($fees('structures'), ['name' => 'Fees', 'session_id' => $this->school->session['id'], 'lines' => [['head_id' => $tuition['id'], 'amount_minor' => 150000]]])->json('data');
    ($this->as)()->postJson($fees("structures/{$structure['id']}/activate"), ['base_version' => $structure['version']])->assertOk();
    $run = ($this->as)()->postJson($fees('runs'), ['kind' => 'monthly', 'session_id' => $this->school->session['id'], 'period' => '2026-03', 'issue_date' => '2026-03-01'])->json('data');
    ($this->as)()->postJson($fees("runs/{$run['id']}/finalize"), ['base_version' => $run['version']])->assertOk();

    orgRule($this->w->c1, 'education_exams.notify_results', ['enabled' => true, 'channels' => ['sms']]);
    orgRule($this->w->c1, 'education_exams.withhold_results_with_dues', true);
    ($this->run)();
    $parent = ($this->parent)($this->rahim['id']);
    $view = $this->asToken($parent->token)->getJson("/api/portal/results?record={$parent->record}")->assertOk()->json('data');
    expect($view['held'])->toBeTrue()->and($view['results'])->toBe([])
        ->and(($this->sms)('education_exams.result_held'))->toHaveCount(1)
        ->and(($this->sms)('education_exams.result_published'))->toHaveCount(0);
    $resultId = collect(($this->as)()->getJson(($this->exams)('students/'.$this->rahim['id'].'/results'))->json('data.results'))->first()['id'];
    $this->asToken($parent->token)->getJson("/api/portal/results/{$resultId}/report-card?record={$parent->record}")->assertForbidden()->assertJsonPath('code', 'result_held');
    // Staff still see every result; with the rule off the family sees it again.
    expect(($this->as)()->getJson(($this->exams)('students/'.$this->rahim['id'].'/results'))->json('data.results'))->toHaveCount(1);
    orgRule($this->w->c1, 'education_exams.withhold_results_with_dues', false);
    expect($this->asToken($parent->token)->getJson("/api/portal/results?record={$parent->record}")->json('data.results'))->toHaveCount(1);
});
