<?php

use Carbon\CarbonImmutable;

/*
 * The setup checklist on the education overview: Education's own steps,
 * then each module's that is on (fees, exams, course registration), what
 * is missing in words and where to do it; people who only look see whether
 * the institution is ready, not the steps.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 04:00:00', 'UTC'));
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'education', 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/education/{$path}";
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);
    $this->steps = fn (?string $token = null) => ($this->as)($token)->getJson(($this->api)('setup-steps'))->assertOk()->json('data');
    $this->step = fn (array $list, string $key) => collect($list['groups'])->flatMap(fn ($group) => $group['steps'])->firstWhere('key', $key);
});

it('lists what a new institution still has to set up, in words, with where to do it', function () {
    $list = ($this->steps)();
    expect($list['ready'])->toBeFalse()
        ->and($list['done'])->toBe(0)
        ->and(collect($list['groups'])->pluck('module')->all())->toBe(['education'])
        ->and(($this->step)($list, 'structure'))->toMatchArray(['done' => false, 'route' => '/education/structure', 'optional' => false])
        ->and(($this->step)($list, 'structure')['detail'])->toContain('preset')
        ->and(($this->step)($list, 'session')['route'])->toBe('/education/structure?tab=years');
});

it('ticks the steps off as the institution is set up', function () {
    $school = eduSchool($this);
    $list = ($this->steps)();
    expect(($this->step)($list, 'structure')['done'])->toBeTrue()
        ->and(($this->step)($list, 'session')['done'])->toBeTrue()
        ->and(($this->step)($list, 'sections')['done'])->toBeTrue()
        ->and(($this->step)($list, 'curriculum'))->toMatchArray(['done' => false])
        ->and(($this->step)($list, 'students')['done'])->toBeFalse();

    newStudent($this, $school, ['name' => 'Rahim', 'guardians' => []])->assertCreated();
    expect(($this->step)(($this->steps)(), 'students')['done'])->toBeTrue();
});

it('adds the steps of the modules that are on, optional ones not counted', function () {
    eduSchool($this);
    $before = ($this->steps)();
    foreach (['education_fees', 'education_exams'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    $list = ($this->steps)();
    expect(collect($list['groups'])->pluck('module')->all())->toBe(['education', 'education_fees', 'education_exams'])
        ->and(($this->step)($list, 'fee_heads'))->toMatchArray(['done' => false, 'route' => '/fees/heads'])
        ->and(($this->step)($list, 'fee_bills')['optional'])->toBeTrue()
        ->and(($this->step)($list, 'first_exam')['optional'])->toBeTrue()
        // Two required fee steps and one exam step more; the optional ones are not counted.
        ->and($list['total'])->toBe($before['total'] + 3);
});

it('tells someone who only looks whether it is ready, not the steps, and keeps to its own institution', function () {
    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view'], 'Viewer')), $this->w->c1);
    $list = ($this->steps)($viewer);
    expect($list)->toMatchArray(['ready' => false, 'can_set_up' => false])->and($list)->not->toHaveKey('groups');

    eduSchool($this);
    $other = orgToken(createMember($this->w->c2), $this->w->c2);
    $theirs = $this->asToken($other)->getJson(($this->api)('setup-steps', $this->w->c2))->assertOk()->json('data');
    expect(($this->step)($theirs, 'structure')['done'])->toBeFalse();

    toggles()->disable($this->w->g1, 'education', 'Test');
    ($this->as)()->getJson(($this->api)('setup-steps'))->assertForbidden();
});
