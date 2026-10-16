<?php

use App\Models\User;
use App\Platform\Portal\Models\PortalLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * TFB-1: teacher feedback. Rounds made, opened (who rates fixed then) and
 * closed by who manages; students or guardians rate only their own
 * teachers, once, inside the dates; nothing ties a rating to who gave it;
 * averages and comments show only from the rule's number of ratings;
 * comments are approved before a teacher sees them; a teacher sees only
 * their own; permission, company and module off.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-10 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2] as $group) {
        foreach (['education', 'hrm', 'teacher_feedback', 'client_portal'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/education/{$path}";
    $this->tfb = fn (string $path = '', $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id.'/teacher-feedback'.($path === '' ? '' : "/{$path}");
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);

    $this->school = eduSchool($this);
    $this->subjects = collect(($this->as)()->getJson(($this->api)('structure/subjects'))->json('data'))->keyBy('code');
    $curriculum = ($this->as)()->postJson(($this->api)('structure/curricula'), ['program_id' => $this->school->program['id'], 'name' => '2026', 'effective_from_year' => 2026, 'status' => 'active'])->assertCreated()->json('data');
    foreach (['BAN1', 'MATH'] as $code) {
        ($this->as)()->postJson(($this->api)('structure/curriculum_items'), ['curriculum_id' => $curriculum['id'], 'level_id' => $this->school->class6['id'], 'subject_id' => $this->subjects[$code]['id'], 'kind' => 'compulsory'])->assertCreated();
    }
    ($this->as)()->patchJson(($this->api)("structure/sections/{$this->school->section['id']}"), ['capacity' => 40, 'base_version' => 1])->assertOk();

    $this->teacher = function (string $name, string $subject) {
        $user = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view', 'teacher_feedback.view_own'], "Teacher {$name}"));
        $employee = hireVia($this, $this->token, $this->w->c1, ['full_name' => $name, 'joined_on' => '2026-01-01'])->assertCreated()->json('data.id');
        DB::table('hrm_employees')->where('id', $employee)->update(['user_id' => $user->id]);
        ($this->as)()->postJson(($this->api)('structure/subject_teachers'), ['section_id' => $this->school->section['id'], 'subject_id' => $this->subjects[$subject]['id'], 'teacher_id' => $employee])->assertCreated();

        return (object) ['token' => orgToken($user, $this->w->c1), 'employee' => $employee];
    };
    $this->rina = ($this->teacher)('Rina Akter', 'MATH');
    $this->kamal = ($this->teacher)('Kamal Hossain', 'BAN1');

    // A student (and, when asked, their guardian) with a portal login.
    $this->join = function (string $name, string $relation = 'self', ?array $student = null) {
        $student ??= newStudent($this, $this->school, ['name' => $name, 'guardians' => []])->assertCreated()->json('data');
        $email = strtolower(str_replace(' ', '.', $name)).".{$relation}@example.com";
        $invitation = ($this->as)()->postJson("/api/organizations/{$this->w->c1->id}/portal/invitations", [
            'subject_type' => 'education.student', 'subject_id' => $student['id'], 'relation' => $relation, 'name' => $name, 'channel' => 'mail', 'email' => $email,
        ])->assertCreated()->json('data');
        $user = User::factory()->create(['email' => $email, 'email_verified_at' => now()]);
        $this->withHeader('Origin', config('app.url'))->actingAs($user, 'web')->postJson('/session/portal/join', ['key' => $invitation['code']])->assertOk();
        $link = PortalLink::query()->where('subject_id', $student['id'])->where('relation', $relation)->sole();
        ($this->as)()->postJson("/api/organizations/{$this->w->c1->id}/portal/links/{$link->id}/approve")->assertOk();

        return (object) ['token' => orgToken($user, $this->w->c1), 'user' => $user, 'student' => $student, 'link' => $link->id];
    };
    $this->openRound = function (array $extra = []) {
        $round = ($this->as)()->postJson(($this->tfb)('rounds'), ['name' => 'Term 1 feedback', 'session_id' => $this->school->session['id'], 'opens_on' => '2026-05-01', 'closes_on' => '2026-05-31', ...$extra])->assertCreated()->json('data');

        return ($this->as)()->postJson(($this->tfb)("rounds/{$round['id']}/open"), ['base_version' => $round['version']])->assertOk()->json('data');
    };
    $this->rate = function (object $rater, array $round, string $teacher, string $subject, array $stars = [5, 4, 4, 5, 3], ?string $comment = null, array $extra = []) {
        $questions = collect($this->asToken($rater->token)->getJson('/api/portal/teacher-feedback'.(isset($extra['record']) ? "?record={$extra['record']}" : ''))->json('data.rounds'))->firstWhere('id', $round['id'])['questions'] ?? [];
        $answers = collect($questions)->values()->mapWithKeys(fn ($question, $i) => [$question['id'] => $stars[$i] ?? 5])->all();

        return $this->asToken($rater->token)->postJson('/api/portal/teacher-feedback', [
            'round_id' => $round['id'], 'teacher_id' => $teacher, 'subject_id' => $this->subjects[$subject]['id'], 'answers' => $answers, 'comment' => $comment, ...$extra,
        ]);
    };
});

it('makes a round with the ready-made questions, opens it with who rates fixed, and keeps it from changing under raters', function () {
    $round = ($this->as)()->postJson(($this->tfb)('rounds'), ['name' => 'Term 1', 'session_id' => $this->school->session['id'], 'opens_on' => '2026-05-01', 'closes_on' => '2026-05-31'])->assertCreated()->json('data');
    expect($round)->toMatchArray(['status' => 'draft', 'who_rates' => null])->and($round['question_texts'])->toHaveCount(5)
        ->and($round['question_texts'][0])->toMatchArray(['en' => 'Explains the lessons clearly', 'bn' => 'পড়া পরিষ্কার করে বোঝান']);

    // A draft takes its own questions; the dates must make sense.
    $round = ($this->as)()->patchJson(($this->tfb)("rounds/{$round['id']}"), ['questions' => [['en' => 'Clear', 'bn' => 'পরিষ্কার'], ['en' => 'Kind']], 'base_version' => 1])->assertOk()->json('data');
    expect($round['question_texts'])->toEqual([['en' => 'Clear', 'bn' => 'পরিষ্কার'], ['en' => 'Kind']]);
    ($this->as)()->patchJson(($this->tfb)("rounds/{$round['id']}"), ['closes_on' => '2026-04-01', 'base_version' => $round['version']])->assertUnprocessable()->assertJsonValidationErrors('closes_on');

    orgRule($this->w->c1, 'teacher_feedback.who_rates', 'guardians');
    $open = ($this->as)()->postJson(($this->tfb)("rounds/{$round['id']}/open"), ['base_version' => $round['version']])->assertOk()->json('data');
    expect($open)->toMatchArray(['status' => 'open', 'who_rates' => 'guardians']);
    // Changing the rule later does not change an open round.
    orgRule($this->w->c1, 'teacher_feedback.who_rates', 'students');
    expect(($this->as)()->getJson(($this->tfb)("rounds/{$round['id']}"))->json('data.who_rates'))->toBe('guardians');

    // Open: questions and name stay; the last day may move.
    ($this->as)()->patchJson(($this->tfb)("rounds/{$round['id']}"), ['questions' => [['en' => 'New']], 'base_version' => $open['version']])->assertStatus(409)->assertJsonPath('code', 'wrong_status');
    $moved = ($this->as)()->patchJson(($this->tfb)("rounds/{$round['id']}"), ['closes_on' => '2026-06-15', 'base_version' => $open['version']])->assertOk()->json('data');
    ($this->as)()->postJson(($this->tfb)("rounds/{$round['id']}/close"), ['base_version' => $moved['version']])->assertOk()->assertJsonPath('data.status', 'closed');
});

it('lets a student rate only their own teachers, once, with every question, and keeps no trace of who rated in the answers', function () {
    $round = ($this->openRound)();
    $rahim = ($this->join)('Rahim');

    $view = $this->asToken($rahim->token)->getJson('/api/portal/teacher-feedback')->assertOk()->json('data');
    expect($view['rounds'])->toHaveCount(1)->and(collect($view['rounds'][0]['teachers'])->pluck('teacher')->sort()->values()->all())->toBe(['Kamal Hossain', 'Rina Akter'])
        ->and(collect($view['rounds'][0]['teachers'])->every(fn ($row) => $row['done'] === false))->toBeTrue();

    ($this->rate)($rahim, $round, $this->rina->employee, 'MATH', comment: 'Very clear lessons.', extra: ['op_id' => 'tap-1'])->assertCreated();
    expect(collect($this->asToken($rahim->token)->getJson('/api/portal/teacher-feedback')->json('data.rounds.0.teachers'))->firstWhere('subject.code', 'MATH')['done'])->toBeTrue();
    // A resent tap is fine; a second rating is refused.
    ($this->rate)($rahim, $round, $this->rina->employee, 'MATH', extra: ['op_id' => 'tap-1'])->assertOk()->assertJsonPath('data.done', true);
    ($this->rate)($rahim, $round, $this->rina->employee, 'MATH')->assertStatus(409)->assertJsonPath('code', 'already_rated');
    // Rina does not teach Bangla; every question needs stars.
    ($this->rate)($rahim, $round, $this->rina->employee, 'BAN1')->assertUnprocessable()->assertJsonPath('code', 'not_their_teacher');
    $this->asToken($rahim->token)->postJson('/api/portal/teacher-feedback', ['round_id' => $round['id'], 'teacher_id' => $this->kamal->employee, 'subject_id' => $this->subjects['BAN1']['id'], 'answers' => ['01aaaaaaaaaaaaaaaaaaaaaaaa' => 5]])
        ->assertUnprocessable()->assertJsonPath('code', 'unanswered_questions');

    // Nothing in the answers or comments points at Rahim, his login, or when he rated.
    $answers = DB::table('tfb_answers')->get();
    $comments = DB::table('tfb_comments')->get();
    expect($answers)->toHaveCount(5)->and($comments)->toHaveCount(1);
    foreach ([...$answers, ...$comments] as $row) {
        $values = implode('|', array_map('strval', (array) $row));
        expect($values)->not->toContain($rahim->student['id'])->not->toContain((string) $rahim->user->id)
            ->and(str_starts_with($row->id, '0000000000'))->toBeTrue()
            ->and(array_keys((array) $row))->not->toContain('created_at');
    }
    $rater = DB::table('tfb_raters')->sole();
    expect(implode('|', array_map('strval', (array) $rater)))->not->toContain($rahim->student['id'])->not->toContain((string) $rahim->user->id)
        ->and(strlen($rater->rater_hash))->toBe(64);
});

it('asks students or guardians as the round says, and only while it is open', function () {
    orgRule($this->w->c1, 'teacher_feedback.who_rates', 'guardians');
    $round = ($this->openRound)();
    $rahim = ($this->join)('Rahim');
    $parent = ($this->join)('Rahim', 'guardian', $rahim->student);

    $this->asToken($rahim->token)->postJson('/api/portal/teacher-feedback', ['round_id' => $round['id'], 'teacher_id' => $this->rina->employee, 'subject_id' => $this->subjects['MATH']['id'], 'answers' => ['x' => 5]])
        ->assertForbidden()->assertJsonPath('code', 'not_your_turn');
    expect($this->asToken($rahim->token)->getJson('/api/portal/teacher-feedback')->json('data.rounds'))->toBe([]);
    ($this->rate)($parent, $round, $this->rina->employee, 'MATH', extra: ['record' => $parent->link])->assertCreated();

    // After the last day, nobody rates.
    $this->travelTo(CarbonImmutable::parse('2026-06-02 04:00:00', 'UTC'));
    $parentAgain = orgToken($parent->user, $this->w->c1);
    $this->asToken($parentAgain)->postJson('/api/portal/teacher-feedback', ['record' => $parent->link, 'round_id' => $round['id'], 'teacher_id' => $this->kamal->employee, 'subject_id' => $this->subjects['BAN1']['id'], 'answers' => ['x' => 5]])
        ->assertStatus(409)->assertJsonPath('code', 'round_closed');
});

it('shows averages and approved comments only from the rule\'s number of ratings, and each teacher only their own', function () {
    orgRule($this->w->c1, 'teacher_feedback.min_responses', 3);
    $round = ($this->openRound)();
    $raters = collect(['Rahim', 'Karim', 'Nusrat'])->map(fn (string $name) => ($this->join)($name));
    ($this->rate)($raters[0], $round, $this->rina->employee, 'MATH', [5, 5, 5, 5, 5], 'Clear and kind.')->assertCreated();
    ($this->rate)($raters[1], $round, $this->rina->employee, 'MATH', [4, 4, 4, 4, 4], 'Call me on 017…')->assertCreated();

    $row = fn () => collect(($this->as)()->getJson(($this->tfb)("rounds/{$round['id']}"))->assertOk()->json('data.rows'))->firstWhere('teacher_id', $this->rina->employee);
    // Two ratings: not enough to show anything.
    expect($row())->toMatchArray(['responses' => 2, 'shown' => false, 'average_centi' => null, 'comments' => []]);

    ($this->rate)($raters[2], $round, $this->rina->employee, 'MATH', [3, 3, 3, 3, 3])->assertCreated();
    $shown = $row();
    expect($shown)->toMatchArray(['responses' => 3, 'shown' => true, 'average_centi' => 400])->and(collect($shown['questions'])->unique()->values()->all())->toBe([400])
        ->and(collect($shown['comments'])->pluck('status')->all())->toBe(['pending', 'pending']);

    // Rina sees nothing until a comment is approved; a hidden one never.
    $mine = fn () => $this->asToken($this->rina->token)->getJson(($this->tfb)('mine'))->assertOk()->json('data.0.rows.0');
    expect($mine())->toMatchArray(['average_centi' => 400, 'comments' => []]);
    $comments = collect($shown['comments']);
    $kind = DB::table('tfb_comments')->where('text', 'Clear and kind.')->value('id');
    $phone = DB::table('tfb_comments')->where('text', 'like', 'Call me%')->value('id');
    ($this->as)()->postJson(($this->tfb)("rounds/{$round['id']}/comments/{$kind}/approve"))->assertOk()->assertJsonPath('data.status', 'approved');
    ($this->as)()->postJson(($this->tfb)("rounds/{$round['id']}/comments/{$phone}/hide"))->assertOk()->assertJsonPath('data.status', 'hidden');
    expect(collect($mine()['comments'])->pluck('text')->all())->toBe(['Clear and kind.']);
    expect($comments)->toHaveCount(2);

    // Kamal has no ratings of his own and never sees Rina's; the rule can stop teachers seeing theirs.
    expect($this->asToken($this->kamal->token)->getJson(($this->tfb)('mine'))->assertOk()->json('data'))->toBe([]);
    $this->asToken($this->kamal->token)->getJson(($this->tfb)("rounds/{$round['id']}"))->assertForbidden();
    orgRule($this->w->c1, 'teacher_feedback.teachers_see_own', false);
    $this->asToken($this->rina->token)->getJson(($this->tfb)('mine'))->assertNotFound();
});

it('keeps rounds to who may see them, the company and the modules being on', function () {
    $round = ($this->openRound)();
    $clerk = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view'], 'Clerk'));
    ($this->as)(orgToken($clerk, $this->w->c1))->getJson(($this->tfb)('rounds'))->assertForbidden();
    $viewer = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view', 'teacher_feedback.view_results'], 'Viewer'));
    ($this->as)(orgToken($viewer, $this->w->c1))->getJson(($this->tfb)('rounds'))->assertOk()->assertJsonCount(1, 'data');
    ($this->as)(orgToken($viewer, $this->w->c1))->postJson(($this->tfb)("rounds/{$round['id']}/close"), ['base_version' => $round['version']])->assertForbidden();

    $stranger = createMember($this->w->c2);
    $this->asToken(orgToken($stranger, $this->w->c2))->getJson(($this->tfb)("rounds/{$round['id']}", $this->w->c2))->assertNotFound();
    $this->asToken(orgToken($stranger, $this->w->c2))->getJson(($this->tfb)('rounds', $this->w->c2))->assertOk()->assertJsonCount(0, 'data');

    $rahim = ($this->join)('Rahim');
    toggles()->disable($this->w->g1, 'client_portal', 'Test', confirm: true);
    $this->asToken($rahim->token)->getJson('/api/portal/teacher-feedback')->assertForbidden();
    toggles()->disable($this->w->g1, 'teacher_feedback', 'Test', confirm: true);
    ($this->as)()->getJson(($this->tfb)('rounds'))->assertForbidden();
});
