<?php

use App\Platform\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Modules\Letters\Models\Letter;

/*
 * LET-3: increment letters from an applied salary increment round. Letters
 * knows only its LetterSource contract (Payroll's IncrementLetters): the
 * round's people (those raised), the round's values in the fields (basic
 * before and after, rate, raise, day; the pay from that day), the round kept
 * on each letter. Not before it is applied, never another company's round,
 * nothing where Payroll is off.
 */

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2] as $group) {
        setPlan($group, 'business');
        toggles()->enable($group, 'payroll', 'Test setup');
        toggles()->enable($group, 'letters', 'Test setup');
    }
    $role = fn (array $permissions, string $name) => orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, $permissions, $name)), $this->w->c1);
    $this->hr = $role(['hrm.view', 'payroll.view', 'payroll.run', 'letters.view', 'letters.design', 'letters.issue', 'letters.salary'], 'HR and payroll');
    $this->approver = $role(['payroll.view', 'payroll.approve'], 'Approver');
    trustedOrgRule($this->w->c1, 'payroll.salary_approval_levels', 1);
    $pay = fn (string $path) => "/api/organizations/{$this->w->c1->id}/payroll/{$path}";
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/letters/{$path}";

    $position = $this->asToken($this->w->token)->postJson("/api/organizations/{$this->w->c1->id}/hrm/positions", ['title' => ['en' => 'Accountant']])->assertCreated()->json('data');
    $hire = fn (string $name) => hireVia($this, $this->w->token, $this->w->b1, ['full_name' => $name, 'joined_on' => '2025-01-01', 'position_id' => $position['id']])->assertCreated()->json('data');
    $this->rahima = $hire('Rahima Akter');
    $this->selim = $hire('Selim Reza');
    $structure = $this->asToken($this->hr)->postJson($pay('structures'), ['code' => 'PLAIN', 'name' => ['en' => 'Basic only'], 'items' => []])->assertCreated()->json('data');
    foreach ([[$this->rahima, 3000000], [$this->selim, 4000000]] as [$employee, $basic]) {
        $this->asToken($this->hr)->postJson($pay("employees/{$employee['id']}/salary"), ['structure_id' => $structure['id'], 'basic_minor' => $basic, 'effective_from' => '2025-01-01'])->assertCreated();
    }

    // A round from 2027-01-01: Rahima +10% by hand, Selim left out.
    $round = $this->asToken($this->hr)->postJson($pay('increments'), ['title' => ['en' => 'Yearly increment 2027'], 'effective_from' => '2027-01-01'])->assertCreated()->json('data');
    $lines = collect($this->asToken($this->hr)->getJson($pay("increments/{$round['id']}"))->json('data.lines'));
    $this->asToken($this->hr)->patchJson($pay("increments/{$round['id']}/lines/".$lines->firstWhere('employee_name', 'Rahima Akter')['id']), ['rate_bp' => 1000, 'reason' => 'Yearly'])->assertOk();
    $this->asToken($this->hr)->patchJson($pay("increments/{$round['id']}/lines/".$lines->firstWhere('employee_name', 'Selim Reza')['id']), ['excluded' => true])->assertOk();
    $this->round = $this->asToken($this->hr)->getJson($pay("increments/{$round['id']}"))->json('data');
    $this->step = function (string $step, ?string $token = null) use ($pay) {
        $this->round = $this->asToken($token ?? $this->hr)->postJson($pay("increments/{$this->round['id']}/{$step}"), ['base_version' => $this->round['version']])->assertOk()->json('data');
    };
    $this->source = fn (?string $token = null, $unit = null) => $this->asToken($token ?? $this->hr)->getJson(($this->api)("sources/payroll.increment_round/{$this->round['id']}", $unit));

    $design = $this->asToken($this->hr)->postJson(($this->api)('templates/presets/increment'))->assertCreated()->json('data');
    $this->design = $this->asToken($this->hr)->patchJson(($this->api)("templates/{$design['id']}"), ['base_version' => $design['version'], 'status' => 'active'])->assertOk()->json('data');
    $this->issue = fn (array $employeeIds, array $extra = []) => $this->asToken($this->hr)->postJson(($this->api)('issued'), [
        'template_id' => $this->design['id'], 'employee_ids' => $employeeIds, 'locale' => 'en', 'op_id' => 'op-'.bin2hex(random_bytes(6)),
        'source' => ['type' => 'payroll.increment_round', 'id' => $this->round['id']], ...$extra,
    ]);
});

it('issues increment letters from an applied round with its values, to the people it raised', function () {
    // Not before the round is applied.
    ($this->source)()->assertStatus(409)->assertJsonPath('code', 'source_refused')
        ->assertJsonPath('message', 'Letters can be issued from this round once it is applied.');
    ($this->issue)([$this->rahima['id']])->assertStatus(409);

    ($this->step)('submit');
    ($this->step)('approve', $this->approver);
    ($this->step)('apply');
    expect($this->round['status'])->toBe('applied');

    // Only Rahima was raised; Selim (left out) is not offered.
    $source = ($this->source)()->assertOk()->json('data');
    expect($source)->toMatchArray(['title' => 'Yearly increment 2027', 'kind' => 'increment'])
        ->and($source['people'])->toBe([['id' => $this->rahima['id'], 'code' => $this->rahima['employee_code'], 'name' => 'Rahima Akter', 'issued' => false]]);
    ($this->issue)([$this->selim['id']])->assertUnprocessable()->assertJsonValidationErrors('employee_ids');

    // Issued before the round's day: the round's values and the pay from its day, not today's.
    $letter = ($this->issue)([$this->rahima['id']])->assertCreated()->json('data.0');
    expect($letter['source'])->toBe(['type' => 'payroll.increment_round', 'id' => $this->round['id']]);
    $full = $this->asToken($this->hr)->getJson(($this->api)("issued/{$letter['id']}"))->assertOk()->json('data');
    $text = collect($full['content']['blocks'])->pluck('text')->filter()->implode("\n");
    expect($text)->toContain('from BDT 30,000.00 to **BDT 33,000.00** (10%, a raise of BDT 3,000.00)')
        ->toContain('**January 1, 2027**')->toContain('**BDT 33,000.00**, as below');
    expect(collect($full['content']['blocks'])->firstWhere('type', 'table')['rows'][0])->toBe(['Basic salary', 'BDT 33,000.00']);

    // The source now shows she has one; the audit names the round.
    expect(($this->source)()->json('data.people.0.issued'))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'letters.issued')->where('new_values->source_id', $this->round['id'])->count())->toBe(1);

    // The preview from the round shows the same values.
    $preview = $this->asToken($this->hr)->postJson(($this->api)("templates/{$this->design['id']}/preview"), [
        'employee_id' => $this->rahima['id'], 'locale' => 'bn', 'source' => ['type' => 'payroll.increment_round', 'id' => $this->round['id']],
    ])->assertOk()->json('data.content.blocks');
    expect(collect($preview)->pluck('text')->filter()->implode("\n"))->toContain('১০%');
});

it('keeps a round to its company, to Payroll being on and to people allowed to issue', function () {
    ($this->step)('submit');
    ($this->step)('approve', $this->approver);
    ($this->step)('apply');

    // Another company of the same client, another client, an unknown kind of source: the same 404.
    $c2 = orgToken(createMember($this->w->c2), $this->w->c2);
    ($this->source)($c2, $this->w->c2)->assertNotFound()->assertJsonPath('code', 'source_not_found');
    $this->asToken($this->hr)->getJson(($this->api)("sources/payroll.nothing/{$this->round['id']}"))->assertNotFound();
    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['letters.view'], 'Viewer')), $this->w->c1);
    ($this->source)($viewer)->assertForbidden();

    // From a branch: only the round's people working there.
    expect(($this->source)(null, $this->w->d1)->assertOk()->json('data.people'))->toBe([]);

    // Payroll off: no round to issue from; letters already issued stay.
    ($this->issue)([$this->rahima['id']])->assertCreated();
    toggles()->disable($this->w->g1, 'payroll', 'Not used');
    ($this->source)()->assertNotFound();
    expect(Letter::query()->whereNotNull('source_id')->count())->toBe(1);
});
