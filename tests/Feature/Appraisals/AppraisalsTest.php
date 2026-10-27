<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Notifications\Models\NotificationDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Modules\Appraisals\Models\Review;
use Modules\Appraisals\Services\Scoring;

/*
 * APR-1: competencies (the usual ones from the rule), review cycles
 * opened for everyone working at a unit with their HRM manager, goals with
 * weights, self and manager reviews, scores per mille and bands from the
 * rules, acknowledging, sending back, reminders; who sees what. Company
 * C1; today is Wednesday 28 October 2026.
 */

beforeEach(function () {
    Queue::fake(); // keep messages queued: a sent message's values are wiped after delivery
    $this->travelTo(CarbonImmutable::parse('2026-10-28 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        setPlan($group, 'business');
        toggles()->enable($group, 'appraisals', 'Test setup');
    }
    $c1 = $this->w->c1;
    $this->hrUser = staffWithRoles($c1, makeRole($c1, ['hrm.view', 'hrm.manage', 'appraisals.view', 'appraisals.manage'], 'HR'));
    $this->hr = orgToken($this->hrUser, $c1);
    $this->bossUser = staffWithRoles($c1, makeRole($c1, ['appraisals.review', 'appraisals.self'], 'Manager'));
    $this->boss = orgToken($this->bossUser, $c1);
    $this->workerUser = staffWithRoles($c1, makeRole($c1, ['appraisals.self'], 'Worker'));
    $this->worker = orgToken($this->workerUser, $c1);
    $this->colleagueUser = staffWithRoles($c1, makeRole($c1, ['appraisals.self'], 'Colleague'));
    $this->colleague = orgToken($this->colleagueUser, $c1);

    $hire = fn (array $data) => hireVia($this, $this->w->token, $this->w->b1, ['joined_on' => '2026-01-01', ...$data])->assertCreated()->json('data');
    $link = fn (array $employee, User $user) => $this->asToken($this->hr)
        ->putJson("/api/organizations/{$c1->id}/hrm/employees/{$employee['id']}/login", ['base_version' => $employee['version'], 'user_id' => $user->id])->assertOk();
    $this->mahmud = $hire(['full_name' => 'Mahmud Hasan']);
    $link($this->mahmud, $this->bossUser);
    $this->rahima = $hire(['full_name' => 'Rahima Akter', 'manager_id' => $this->mahmud['id']]);
    $link($this->rahima, $this->workerUser);
    $this->karim = $hire(['full_name' => 'Karim Uddin', 'manager_id' => $this->mahmud['id']]);
    $link($this->karim, $this->colleagueUser);

    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $c1)->id."/appraisals/{$path}";
    $this->asToken($this->hr)->postJson(($this->api)('competencies/defaults'))->assertOk()->assertJsonPath('data.added', 5);
    $this->cycle = function (array $data = []) {
        $cycle = $this->asToken($this->hr)->postJson(($this->api)('cycles'), [
            'name' => ['en' => 'Annual review 2026', 'bn' => 'বার্ষিক মূল্যায়ন ২০২৬'], 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'goals_due' => '2026-11-10', 'self_due' => '2026-11-20', 'manager_due' => '2026-11-30', ...$data,
        ])->assertCreated()->json('data');

        return $this->asToken($this->hr)->postJson(($this->api)("cycles/{$cycle['id']}/open"), ['base_version' => $cycle['version']])->assertOk()->json('data');
    };
    $this->reviewOf = fn (array $employee) => Review::query()->withoutGlobalScopes()->where('employee_id', $employee['id'])->sole();
    $this->show = fn (string $id, ?string $token = null) => $this->asToken($token ?? $this->worker)->getJson(($this->api)("reviews/{$id}"));
    $this->step = fn (array $review, string $step, string $token, array $data = []) => $this->asToken($token)
        ->postJson(($this->api)("reviews/{$review['id']}/{$step}"), ['base_version' => $review['version'], ...$data]);
    $this->goals = fn (array $review, array $goals, ?string $token = null) => $this->asToken($token ?? $this->worker)
        ->putJson(($this->api)("reviews/{$review['id']}/goals"), ['base_version' => $review['version'], 'goals' => $goals]);
    // Every item of a review rated alike (or as given by title / name), by its side.
    $this->rateAll = function (array $review, string $token, array $by = [], int $default = 4) {
        $items = [...$review['goals'], ...$review['competencies']];

        return $this->asToken($token)->putJson(($this->api)("reviews/{$review['id']}/ratings"), [
            'base_version' => $review['version'], 'summary' => 'A good year',
            'ratings' => array_map(fn ($item) => ['id' => $item['rating_id'], 'rating' => $by[$item['title'] ?? $item['name']] ?? $default, 'comment' => 'Fine'], $items),
        ]);
    };
});

it('scores in whole numbers per mille, goals weighed and mixed by their share', function () {
    expect(Scoring::place(1, 1, 5))->toBe(0)
        ->and(Scoring::place(4, 1, 5))->toBe(750)
        ->and(Scoring::place(5, 1, 5))->toBe(1000)
        ->and(Scoring::place(2, 1, 4))->toBe(333)
        ->and(Scoring::goals([['weight' => 60, 'rating' => 4], ['weight' => 40, 'rating' => 3]], 1, 5))->toBe(650)
        ->and(Scoring::goals([], 1, 5))->toBeNull()
        ->and(Scoring::competencies([4, 4, 5], 1, 5))->toBe(833)
        ->and(Scoring::overall(650, 750, 70))->toBe(680)
        ->and(Scoring::overall(null, 750, 70))->toBe(750)
        ->and(Scoring::band(680, [['min' => 900, 'key' => 'top'], ['min' => 450, 'key' => 'solid'], ['min' => 0, 'key' => 'low']]))->toBe('solid')
        ->and(Scoring::band(900, [['min' => 0, 'key' => 'low'], ['min' => 900, 'key' => 'top']]))->toBe('top');
});

it('keeps competencies and opens a cycle for everyone working there, with their manager', function () {
    $this->asToken($this->worker)->postJson(($this->api)('competencies'), ['code' => 'X', 'name' => ['en' => 'X']])->assertForbidden();
    $this->asToken($this->hr)->postJson(($this->api)('competencies'), ['code' => 'teamwork', 'name' => ['en' => 'Team']])->assertUnprocessable()->assertJsonValidationErrors('code');
    expect($this->asToken($this->worker)->getJson(($this->api)('competencies'))->assertOk()->json('data'))->toHaveCount(5);

    $this->asToken($this->hr)->postJson(($this->api)('cycles'), ['name' => ['en' => 'Bad'], 'period_from' => '2026-01-01', 'period_to' => '2025-12-31', 'goals_due' => '2026-11-10', 'manager_due' => '2026-11-01'])
        ->assertUnprocessable()->assertJsonValidationErrors(['period_to', 'manager_due']);
    $this->asToken($this->worker)->postJson(($this->api)('cycles'), ['name' => ['en' => 'Mine'], 'period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'goals_due' => '2026-11-10', 'manager_due' => '2026-11-30'])
        ->assertForbidden();

    $cycle = ($this->cycle)();
    expect($cycle)->toMatchArray(['status' => 'open', 'reviews' => 3, 'self_review' => true, 'goal_share_percent' => 70]);
    $review = ($this->reviewOf)($this->rahima);
    expect($review->manager_user_id)->toBe($this->bossUser->id)->and($review->stage)->toBe('goals')
        ->and(($this->reviewOf)($this->mahmud)->manager_user_id)->toBeNull()
        ->and(NotificationDelivery::query()->where('notification_key', 'appraisals.opened')->pluck('user_id')->all())->toContain($this->workerUser->id, $this->bossUser->id);
    $this->asToken($this->hr)->postJson(($this->api)("cycles/{$cycle['id']}/open"), ['base_version' => $cycle['version']])->assertStatus(409);

    $people = $this->asToken($this->hr)->getJson(($this->api)("cycles/{$cycle['id']}"))->assertOk()->json('data.people');
    expect(collect($people)->pluck('employee.name')->all())->toBe(['Karim Uddin', 'Mahmud Hasan', 'Rahima Akter'])
        ->and(collect($people)->firstWhere('employee.name', 'Rahima Akter'))->toMatchArray(['manager_name' => 'Mahmud Hasan', 'stage' => 'goals', 'due' => '2026-11-10']);
});

it('goes from goals through self and manager reviews to an acknowledged result', function () {
    ($this->cycle)();
    $id = ($this->reviewOf)($this->rahima)->id;
    $review = ($this->show)($id)->assertOk()->json('data');
    expect($review['role'])->toBe('employee')->and($review['competencies'])->toHaveCount(5)->and($review['can'])->toMatchArray(['edit_goals' => true, 'submit_goals' => true, 'approve_goals' => false]);

    // Goals: weights must add up to 100 to send.
    $review = ($this->goals)($review, [['title' => 'Close the books monthly', 'weight' => 60], ['title' => 'Train two juniors', 'weight' => 30]])->assertOk()->json('data');
    ($this->step)($review, 'submit_goals', $this->worker)->assertUnprocessable()->assertJsonValidationErrors('goals');
    $review = ($this->goals)($review, [['id' => $review['goals'][0]['id'], 'title' => 'Close the books monthly', 'weight' => 60], ['id' => $review['goals'][1]['id'], 'title' => 'Train two juniors', 'weight' => 40]])->json('data');
    ($this->step)($review, 'approve_goals', $this->worker)->assertForbidden()->assertJsonPath('code', 'not_yours');
    $review = ($this->step)($review, 'submit_goals', $this->worker)->assertOk()->json('data');
    expect(NotificationDelivery::query()->where('notification_key', 'appraisals.your_turn')->pluck('user_id')->all())->toBe([$this->bossUser->id]);
    ($this->goals)($review, [['title' => 'Something else', 'weight' => 100]])->assertForbidden();

    // The manager approves the goals: the self review starts.
    $this->asToken($this->colleague)->getJson(($this->api)("reviews/{$id}"))->assertNotFound();
    $team = $this->asToken($this->boss)->getJson(($this->api)('team'))->assertOk()->json('data');
    expect(collect($team)->pluck('employee.name')->sort()->values()->all())->toBe(['Karim Uddin', 'Rahima Akter']);
    $review = ($this->step)(($this->show)($id, $this->boss)->json('data'), 'approve_goals', $this->boss)->assertOk()->json('data');
    expect($review['stage'])->toBe('self');

    // Self review: ratings from the scale only, every item rated before sending.
    $review = ($this->show)($id)->json('data');
    $bad = $this->asToken($this->worker)->putJson(($this->api)("reviews/{$id}/ratings"), ['base_version' => $review['version'], 'ratings' => [['id' => $review['goals'][0]['rating_id'], 'rating' => 9]]]);
    $bad->assertUnprocessable()->assertJsonValidationErrors('ratings.0.rating');
    $half = $this->asToken($this->worker)->putJson(($this->api)("reviews/{$id}/ratings"), ['base_version' => $review['version'], 'ratings' => [['id' => $review['goals'][0]['rating_id'], 'rating' => 5]]])->assertOk()->json('data');
    ($this->step)($half, 'submit_self', $this->worker)->assertUnprocessable()->assertJsonValidationErrors('ratings');
    $review = ($this->rateAll)($half, $this->worker, [], 5)->assertOk()->json('data');
    // The manager does not see the self review until it is sent.
    expect(($this->show)($id, $this->boss)->json('data.goals.0.self_rating'))->toBeNull();
    $review = ($this->step)($review, 'submit_self', $this->worker)->assertOk()->json('data');
    expect($review['stage'])->toBe('manager');

    // Manager review: the employee sees none of it until it is sent; then the scores and the band.
    $mine = ($this->show)($id, $this->boss)->json('data');
    expect($mine['goals'][0]['self_rating'])->toBe(5)->and($mine['can']['submit_manager'])->toBeTrue();
    $mine = ($this->rateAll)($mine, $this->boss, ['Close the books monthly' => 4, 'Train two juniors' => 3], 4)->assertOk()->json('data');
    expect(($this->show)($id)->json('data'))->toMatchArray(['score' => null, 'manager_summary' => null])
        ->and(($this->show)($id)->json('data.goals.0.manager_rating'))->toBeNull();
    $done = ($this->step)($mine, 'submit_manager', $this->boss)->assertOk()->json('data');
    // Goals (60 x 750 + 40 x 500) / 100 = 650, competencies 750, overall 650 x 70% + 750 x 30% = 680: "solid".
    expect($done)->toMatchArray(['stage' => 'acknowledge', 'score' => 680, 'band' => 'solid', 'goals_score' => 650, 'competencies_score' => 750]);
    $seen = ($this->show)($id)->json('data');
    expect($seen)->toMatchArray(['score' => 680, 'band' => 'solid', 'manager_summary' => 'A good year'])->and($seen['can']['acknowledge'])->toBeTrue();

    $final = ($this->step)($seen, 'acknowledge', $this->worker, ['note' => 'Thank you'])->assertOk()->json('data');
    expect($final)->toMatchArray(['stage' => 'done', 'employee_comment' => 'Thank you'])
        ->and(collect($final['history'])->pluck('to')->all())->toBe(['goals', 'goals_sent', 'self', 'manager', 'acknowledge', 'done'])
        ->and(AuditLog::query()->where('action', 'appraisals.submit_manager')->exists())->toBeTrue()
        ->and(json_encode(NotificationDelivery::query()->where('notification_key', 'appraisals.your_turn')->get()->pluck('data')))->not->toContain('680');
});

it('never lets anyone review themselves: without a manager, the people managing appraisals do', function () {
    ($this->cycle)();
    $id = ($this->reviewOf)($this->mahmud)->id;
    $review = ($this->show)($id, $this->boss)->json('data');
    expect($review['role'])->toBe('employee');
    $review = ($this->goals)($review, [['title' => 'Grow the team', 'weight' => 100]], $this->boss)->assertOk()->json('data');
    $review = ($this->step)($review, 'submit_goals', $this->boss)->assertOk()->json('data');
    ($this->step)($review, 'approve_goals', $this->boss)->assertForbidden();
    expect(NotificationDelivery::query()->where('notification_key', 'appraisals.your_turn')->pluck('user_id')->all())->toContain($this->hrUser->id)->not->toContain($this->bossUser->id);
    ($this->step)(($this->show)($id, $this->hr)->json('data'), 'approve_goals', $this->hr)->assertOk()->assertJsonPath('data.stage', 'self');
});

it('skips the self review when the rule says so, and is sent back a step by HR with a reason', function () {
    trustedOrgRule($this->w->c1, 'appraisals.self_review', false);
    ($this->cycle)();
    $id = ($this->reviewOf)($this->rahima)->id;
    $review = ($this->goals)(($this->show)($id)->json('data'), [['title' => 'Close the books monthly', 'weight' => 100]])->json('data');
    $review = ($this->step)(($this->show)($id, $this->boss)->json('data'), 'approve_goals', $this->boss)->assertOk()->json('data');
    expect($review['stage'])->toBe('manager')->and(NotificationDelivery::query()->where('notification_key', 'appraisals.your_turn')->count())->toBe(0);
    $review = ($this->step)(($this->rateAll)($review, $this->boss)->json('data'), 'submit_manager', $this->boss)->assertOk()->json('data');
    expect($review['score'])->toBe(750);

    ($this->step)($review, 'send_back', $this->boss)->assertUnprocessable()->assertJsonValidationErrors('note');
    ($this->step)($review, 'send_back', $this->boss, ['note' => 'Too kind'])->assertForbidden();
    $back = ($this->step)(($this->show)($id, $this->hr)->json('data'), 'send_back', $this->hr, ['note' => 'Ratings need evidence'])->assertOk()->json('data');
    expect($back)->toMatchArray(['stage' => 'manager', 'score' => null, 'band' => null])
        ->and(collect($back['history'])->last())->toMatchArray(['to' => 'manager', 'note' => 'Ratings need evidence'])
        ->and(AuditLog::query()->where('action', 'appraisals.send_back')->sole()->reason)->toBe('Ratings need evidence');
});

it('reminds whoever must act before a step ends, and the bell shows what waits', function () {
    ($this->cycle)();
    $this->asToken($this->worker)->getJson('/api/attention')->assertOk()->assertJsonFragment(['key' => 'appraisals.my_review', 'count' => 1]);
    $this->travelTo(CarbonImmutable::parse('2026-11-07 03:00:00', 'UTC'));
    Artisan::call('appraisals:remind');
    expect(NotificationDelivery::query()->where('notification_key', 'appraisals.reminder')->pluck('user_id')->all())->toContain($this->workerUser->id, $this->colleagueUser->id);
    $this->travelTo(CarbonImmutable::parse('2026-11-08 03:00:00', 'UTC'));
    $before = NotificationDelivery::query()->where('notification_key', 'appraisals.reminder')->count();
    Artisan::call('appraisals:remind');
    expect(NotificationDelivery::query()->where('notification_key', 'appraisals.reminder')->count())->toBe($before);
});

it('keeps appraisals to the people concerned, to the company and to the module being on', function () {
    $cycle = ($this->cycle)();
    $id = ($this->reviewOf)($this->rahima)->id;
    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['appraisals.view'], 'Viewer')), $this->w->c1);
    expect(($this->show)($id, $viewer)->assertOk()->json('data.role'))->toBe('oversees');
    ($this->goals)(($this->show)($id, $viewer)->json('data'), [['title' => 'X goal', 'weight' => 100]], $viewer)->assertForbidden();
    $this->asToken($this->colleague)->getJson(($this->api)('cycles'))->assertForbidden();
    $this->asToken($this->worker)->getJson(($this->api)('team'))->assertForbidden();
    expect($this->asToken($this->worker)->getJson(($this->api)('me'))->assertOk()->json('data'))->toHaveCount(1);

    $c2 = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['appraisals.view', 'appraisals.manage'], 'C2 HR')), $this->w->c2);
    $this->asToken($c2)->getJson(($this->api)("reviews/{$id}", $this->w->c2))->assertNotFound();
    $this->asToken($c2)->getJson(($this->api)("cycles/{$cycle['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($c2)->getJson(($this->api)('cycles', $this->w->c2))->assertOk()->assertJsonPath('data', []);

    // Closed: read only.
    $closed = $this->asToken($this->hr)->postJson(($this->api)("cycles/{$cycle['id']}/close"), ['base_version' => $cycle['version']])->assertOk()->json('data');
    expect($closed['status'])->toBe('closed');
    ($this->goals)(($this->show)($id)->json('data'), [['title' => 'Late goal', 'weight' => 100]])->assertStatus(409)->assertJsonPath('code', 'not_open');

    toggles()->disable($this->w->g1, 'appraisals', 'Not used');
    ($this->show)($id)->assertForbidden();
    expect(Review::query()->withoutGlobalScopes()->count())->toBe(3);
});
