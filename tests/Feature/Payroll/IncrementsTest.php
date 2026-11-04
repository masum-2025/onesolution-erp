<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Tenancy\Context\CurrentContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Modules\Appraisals\Models\Cycle;
use Modules\Appraisals\Models\Review;

/*
 * PAY-4: salary increment rounds. Rates come from the appraisal band
 * (rule payroll.increment_by_band, capped by payroll.increment_max_percent)
 * or are set by hand with a reason; new basics are rounded
 * (payroll.increment_round_to); approval takes
 * payroll.salary_approval_levels other people; applying sets salaries from
 * the day and tells the people without an amount. Months already sent or
 * approved stay closed. Appraisal managers never see salaries.
 */

beforeEach(function () {
    Queue::fake(); // keep messages queued: a sent message's values are wiped after delivery
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2] as $group) {
        setPlan($group, 'business');
        toggles()->enable($group, 'attendance', 'Test setup');
        toggles()->enable($group, 'payroll', 'Test setup');
        toggles()->enable($group, 'appraisals', 'Test setup');
    }
    $this->runner = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'payroll.view', 'payroll.run'], 'Payroll clerk')), $this->w->c1);
    $this->approver = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['payroll.view', 'payroll.approve'], 'Approver')), $this->w->c1);
    $this->approver2 = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['payroll.view', 'payroll.approve'], 'Second approver')), $this->w->c1);
    $this->workerUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['attendance.punch'], 'Worker'));
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/payroll/{$path}";

    $hire = fn (string $name, string $joined) => hireVia($this, $this->w->token, $this->w->b1, ['full_name' => $name, 'joined_on' => $joined])->assertCreated()->json('data');
    $this->rahima = $hire('Rahima Akter', '2025-01-01');
    $this->selim = $hire('Selim Reza', '2025-01-01');
    $this->tania = $hire('Tania Islam', '2025-01-01');
    $this->asToken($this->w->token)->putJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$this->rahima['id']}/login", ['base_version' => $this->rahima['version'], 'user_id' => $this->workerUser->id])->assertOk();

    $this->structure = $this->asToken($this->runner)->postJson(($this->api)('structures'), ['code' => 'PLAIN', 'name' => ['en' => 'Basic only'], 'items' => []])->assertCreated()->json('data');
    foreach ([[$this->rahima, 3003000], [$this->selim, 4000000], [$this->tania, 2500000]] as [$employee, $basic]) {
        $this->asToken($this->runner)->postJson(($this->api)("employees/{$employee['id']}/salary"), ['structure_id' => $this->structure['id'], 'basic_minor' => $basic, 'effective_from' => '2025-01-01'])->assertCreated();
    }
    trustedOrgRule($this->w->c1, 'payroll.increment_round_to', ['amount' => 10000, 'currency' => 'BDT']);

    // A finished appraisal cycle: Rahima outstanding, Selim solid; Tania has no review.
    app(CurrentContext::class)->clear();
    $cycle = new Cycle;
    $cycle->fill([
        'organization_id' => $this->w->c1->id, 'unit_id' => $this->w->c1->id, 'period_from' => '2025-07-01', 'period_to' => '2026-06-30',
        'goals_due' => '2025-08-01', 'self_due' => '2026-07-10', 'manager_due' => '2026-07-20', 'status' => Cycle::CLOSED,
        'goal_share_percent' => 70, 'self_review' => true, 'scale' => [['value' => 1, 'name' => ['en' => 'Low']]], 'created_by' => $this->w->owner->id, 'version' => 1,
    ]);
    $cycle->putTexts('name', ['en' => 'Year 2025-26']);
    $cycle->save();
    $this->cycle = $cycle;
    foreach ([[$this->rahima, 930, 'outstanding'], [$this->selim, 500, 'solid']] as [$employee, $score, $band]) {
        (new Review)->fill([
            'organization_id' => $this->w->c1->id, 'cycle_id' => $cycle->id, 'unit_id' => $this->w->b1->id, 'employee_id' => $employee['id'],
            'stage' => Review::DONE, 'score' => $score, 'band' => $band, 'version' => 1,
        ])->save();
    }

    $this->open = fn (array $data = [], ?string $token = null) => $this->asToken($token ?? $this->runner)->postJson(($this->api)('increments'), [
        'title' => ['en' => 'Yearly increment 2027'], 'effective_from' => '2027-01-01', 'cycle_id' => $this->cycle->id, ...$data,
    ]);
    $this->step = fn (array $round, string $step, ?string $token = null, array $data = []) => $this->asToken($token ?? $this->runner)
        ->postJson(($this->api)("increments/{$round['id']}/{$step}"), ['base_version' => $round['version'], ...$data]);
    $this->show = fn (array $round, ?string $token = null) => $this->asToken($token ?? $this->runner)->getJson(($this->api)("increments/{$round['id']}"));
    $this->lineOf = fn (array $round, string $name) => collect(($this->show)($round)->json('data.lines'))->firstWhere('employee_name', $name);
    $this->change = fn (array $round, array $line, array $data) => $this->asToken($this->runner)->patchJson(($this->api)("increments/{$round['id']}/lines/{$line['id']}"), $data);
});

it('suggests rates from the appraisal bands, rounds them, is approved by other people at each level, and sets the salaries', function () {
    trustedOrgRule($this->w->c1, 'payroll.salary_approval_levels', 2);
    $sources = $this->asToken($this->runner)->getJson(($this->api)('increments/sources'))->assertOk()->json('data');
    expect(collect($sources['cycles'])->pluck('id')->all())->toBe([$this->cycle->id])
        ->and(collect($sources['bands'])->pluck('key')->first())->toBe('outstanding');

    $round = ($this->open)()->assertCreated()->json('data');
    // Rahima 30,030 + 10% = 33,033 -> 33,000; Selim 40,000 + 5% = 42,000; Tania: no band, no increment.
    expect($round)->toMatchArray(['status' => 'draft', 'employees' => 2, 'current_minor' => 7003000, 'new_minor' => 7500000]);
    expect(($this->lineOf)($round, 'Rahima Akter'))->toMatchArray(['band' => 'outstanding', 'score' => 930, 'rate_bp' => 1000, 'rate_source' => 'rule', 'new_minor' => 3300000])
        ->and(($this->lineOf)($round, 'Selim Reza'))->toMatchArray(['rate_bp' => 500, 'new_minor' => 4200000])
        ->and(($this->lineOf)($round, 'Tania Islam'))->toMatchArray(['band' => null, 'rate_bp' => 0, 'rate_source' => 'none', 'new_minor' => 2500000]);

    $round = ($this->step)($round, 'submit')->assertOk()->json('data');
    ($this->step)($round, 'approve', $this->runner)->assertForbidden();
    $round = ($this->step)($round, 'approve', $this->approver)->assertOk()->json('data');
    expect($round)->toMatchArray(['status' => 'pending_approval', 'approvals' => 1, 'approvals_needed' => 2]);
    // The same person cannot give the second level.
    ($this->step)($round, 'approve', $this->approver)->assertForbidden()->assertJsonPath('code', 'already_approved');
    $round = ($this->step)($round, 'approve', $this->approver2)->assertOk()->json('data');
    expect($round['status'])->toBe('approved');

    $round = ($this->step)($round, 'apply')->assertOk()->json('data');
    expect($round['status'])->toBe('applied');
    $pay = $this->asToken($this->runner)->getJson(($this->api)("employees/{$this->rahima['id']}"))->assertOk()->json('data');
    $latest = collect($pay['salaries'])->sortByDesc('effective_from')->first();
    expect($latest)->toMatchArray(['basic_minor' => 3300000, 'effective_from' => '2027-01-01']);

    // January's payroll reads the new basic.
    $run = $this->asToken($this->runner)->postJson(($this->api)('runs'), ['period' => '2027-01'])->assertCreated()->json('data');
    $run = $this->asToken($this->runner)->postJson(($this->api)("runs/{$run['id']}/calculate"), ['base_version' => $run['version']])->assertOk()->json('data');
    $slip = collect($this->asToken($this->runner)->getJson(($this->api)("runs/{$run['id']}"))->json('data.slips'))->firstWhere('employee_name', 'Rahima Akter');
    expect($slip['basic_minor'])->toBe(3300000);

    // Rahima (with a login) is told, without any amount; each salary is audited on its own.
    $told = NotificationDelivery::query()->where('notification_key', 'payroll.salary_revised')->get();
    expect($told)->toHaveCount(1)
        ->and($told[0]->user_id)->toBe($this->workerUser->id)
        ->and($told[0]->data)->toHaveKey('date')
        ->and(json_encode($told[0]->data))->not->toContain('33')->not->toContain('3300000')
        ->and(AuditLog::query()->where('action', 'payroll.salary_set')->where('new_values->effective_from', '2027-01-01')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'payroll.increment_applied')->count())->toBe(1);
});

it('lets payroll staff set a rate or amount by hand, with a reason and within the rules', function () {
    $round = ($this->open)()->assertCreated()->json('data');
    $tania = ($this->lineOf)($round, 'Tania Islam');

    ($this->change)($round, $tania, ['rate_bp' => 800])->assertUnprocessable()->assertJsonValidationErrors('reason');
    ($this->change)($round, $tania, ['new_minor' => 2400000, 'reason' => 'Lower'])->assertUnprocessable()->assertJsonValidationErrors('new_minor');
    ($this->change)($round, $tania, ['rate_bp' => 2500, 'reason' => 'Too much'])->assertUnprocessable()->assertJsonValidationErrors('rate_bp');
    $line = ($this->change)($round, $tania, ['new_minor' => 2700000, 'reason' => 'Took over the stores'])->assertOk()->json('data');
    expect($line)->toMatchArray(['rate_source' => 'hand', 'rate_bp' => 800, 'new_minor' => 2700000, 'reason' => 'Took over the stores']);

    // Filled again from the appraisal: the hand line stays as set.
    $round = ($this->show)($round)->json('data');
    $round = ($this->step)($round, 'fill')->assertOk()->json('data');
    expect(($this->lineOf)($round, 'Tania Islam'))->toMatchArray(['rate_source' => 'hand', 'new_minor' => 2700000]);

    // Back on the rule; Selim left out.
    ($this->change)($round, ($this->lineOf)($round, 'Tania Islam'), ['rate_bp' => null, 'new_minor' => null])->assertOk()->assertJsonPath('data.rate_source', 'none');
    ($this->change)($round, ($this->lineOf)($round, 'Selim Reza'), ['excluded' => true])->assertOk();
    expect(($this->show)($round)->json('data'))->toMatchArray(['employees' => 1, 'new_minor' => 3300000]);
    expect(AuditLog::query()->where('action', 'payroll.increment_line_changed')->count())->toBe(3);

    // A company rule decides the band's rate.
    trustedOrgRule($this->w->c1, 'payroll.increment_by_band', ['outstanding' => '12', 'solid' => '5']);
    $round = ($this->show)($round)->json('data');
    $round = ($this->step)($round, 'fill')->assertOk()->json('data');
    expect(($this->lineOf)($round, 'Rahima Akter'))->toMatchArray(['rate_bp' => 1200, 'new_minor' => 3360000]);
});

it('keeps months already sent or approved closed, and holds a line whose salary changed meanwhile', function () {
    // Without arrears (rule payroll.increment_arrears off): November is sent, so a round from November cannot open.
    trustedOrgRule($this->w->c1, 'payroll.increment_arrears', false);
    $run = $this->asToken($this->runner)->postJson(($this->api)('runs'), ['period' => '2026-11'])->assertCreated()->json('data');
    $run = $this->asToken($this->runner)->postJson(($this->api)("runs/{$run['id']}/calculate"), ['base_version' => $run['version']])->assertOk()->json('data');
    $run = $this->asToken($this->runner)->postJson(($this->api)("runs/{$run['id']}/submit"), ['base_version' => $run['version']])->assertOk()->json('data');
    ($this->open)(['effective_from' => '2026-11-01'])->assertConflict()->assertJsonPath('code', 'increment_month_locked');
    ($this->open)(['effective_from' => '2026-10-01'])->assertConflict();

    $round = ($this->open)()->assertCreated()->json('data');
    $round = ($this->step)($round, 'submit')->assertOk()->json('data');
    $round = ($this->step)($round, 'approve', $this->approver)->assertOk()->json('data');

    // Selim's salary changes before the round is applied: his line is held, Rahima's goes ahead.
    $this->asToken($this->runner)->postJson(($this->api)("employees/{$this->selim['id']}/salary"), ['structure_id' => $this->structure['id'], 'basic_minor' => 4100000, 'effective_from' => '2026-12-01'])->assertCreated();
    $round = ($this->step)($round, 'apply')->assertOk()->json('data');
    expect(($this->lineOf)($round, 'Selim Reza'))->toMatchArray(['problem' => 'salary_changed', 'applied_salary_id' => null])
        ->and(($this->lineOf)($round, 'Rahima Akter')['applied_salary_id'])->not->toBeNull();
    ($this->step)(($this->show)($round)->json('data'), 'cancel')->assertConflict();
});

it('works without Appraisals (rates by hand), and only for payroll people of the company', function () {
    toggles()->disable($this->w->c1, 'appraisals', 'Test');
    expect($this->asToken($this->runner)->getJson(($this->api)('increments/sources'))->json('data'))->toBe(['cycles' => [], 'bands' => []]);
    ($this->open)()->assertUnprocessable()->assertJsonValidationErrors('cycle_id');
    $round = ($this->open)(['cycle_id' => null])->assertCreated()->json('data');
    expect($round['employees'])->toBe(0);
    ($this->step)($round, 'submit')->assertConflict()->assertJsonPath('code', 'increment_empty');
    ($this->change)($round, ($this->lineOf)($round, 'Tania Islam'), ['rate_bp' => 500, 'reason' => 'Yearly'])->assertOk()->assertJsonPath('data.new_minor', 2625000);

    // An appraisal manager (no payroll.view) sees no salaries; people without payroll.run change nothing.
    $manager = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['appraisals.view', 'appraisals.manage'], 'Appraisal manager')), $this->w->c1);
    $this->asToken($manager)->getJson(($this->api)('increments'))->assertForbidden();
    ($this->show)($round, $manager)->assertForbidden();
    ($this->open)([], $this->approver)->assertForbidden();
    ($this->step)(($this->show)($round)->json('data'), 'cancel', $this->approver)->assertForbidden();

    // Another company never reaches this one's round.
    $other = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['payroll.view', 'payroll.run'], 'Payroll clerk')), $this->w->c2);
    $this->asToken($other)->getJson(($this->api)("increments/{$round['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($other)->getJson(($this->api)("increments/{$round['id']}"))->assertNotFound();

    // Payroll off: closed, nothing deleted.
    toggles()->disable($this->w->c1, 'payroll', 'Test');
    $this->asToken($this->runner)->getJson(($this->api)('increments'))->assertForbidden();
    toggles()->enable($this->w->c1, 'payroll', 'Test');
    ($this->show)($round)->assertOk();
});
