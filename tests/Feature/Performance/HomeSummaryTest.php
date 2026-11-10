<?php

use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Tenancy\Enums\MembershipType;

/*
 * PERF-1: the home page asks only for counts (?summary=1) instead of every rule
 * (about 500 KB) and every module: the same numbers the full lists give, behind
 * the same access checks, never another company's or partner's.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    toggles()->enable($this->w->g1, 'payroll', 'Test setup');
    $this->owner = createMember($this->w->c1, MembershipType::Owner);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->url = fn (string $what, $unit = null, string $query = '') => '/api/organizations/'.($unit ?? $this->w->c1)->id."/{$what}{$query}";
});

it('counts the rules set here, the same as the full list', function () {
    orgRule($this->w->c1, 'attendance.late_grace_minutes', 20);
    orgRule($this->w->c1, 'attendance.late_grace_minutes', ['min' => 0, 'max' => 30], RuleMode::Constrain);
    orgRule($this->w->g1, 'payroll.increment_arrears', false);

    $full = collect($this->asToken($this->token)->getJson(($this->url)('rules'))->assertOk()->json('data'))
        ->flatMap(fn (array $group) => collect($group['categories'])->flatMap(fn (array $category) => $category['rules']));
    $summary = $this->asToken($this->token)->getJson(($this->url)('rules', query: '?summary=1'))->assertOk()->json('data');

    expect($summary)->toBe([
        'own_count' => $full->filter(fn (array $rule) => $rule['own']['value'] !== null || $rule['own']['constraint'] !== null)->count(),
        'total' => $full->count(),
    ])->and($summary['own_count'])->toBe(1);

    // The module filter applies to the count as to the list.
    $payroll = $this->asToken($this->token)->getJson(($this->url)('rules', query: '?summary=1&module=attendance'))->assertOk()->json('data');
    expect($payroll['own_count'])->toBe(1)->and($payroll['total'])->toBeLessThan($summary['total']);
});

it('counts the modules on here, the same as the full list', function () {
    toggles()->enable($this->w->c1, 'crm', 'Sales team starts');
    $full = collect($this->asToken($this->token)->getJson(($this->url)('modules'))->assertOk()->json('data'));

    $this->asToken($this->token)->getJson(($this->url)('modules', query: '?summary=1'))->assertOk()
        ->assertExactJson(['data' => ['enabled' => $full->where('enabled', true)->count(), 'total' => $full->count()]]);
});

it('checks the summary flag', function () {
    $this->asToken($this->token)->getJson(($this->url)('rules', query: '?summary=maybe'))->assertUnprocessable()->assertJsonValidationErrors('summary');
    $this->asToken($this->token)->getJson(($this->url)('modules', query: '?summary=maybe'))->assertUnprocessable()->assertJsonValidationErrors('summary');
});

it('gives the counts only to those who may see the lists', function () {
    $staff = orgToken(createMember($this->w->c1, MembershipType::Staff), $this->w->c1);
    foreach (['rules', 'modules'] as $what) {
        $fullStatus = $this->asToken($staff)->getJson(($this->url)($what))->status();
        expect($this->asToken($staff)->getJson(($this->url)($what, query: '?summary=1'))->status())->toBe($fullStatus);
    }
    // A client's portal person (parent, customer) gets neither.
    $portal = orgToken(createMember($this->w->c1, MembershipType::Portal), $this->w->c1);
    foreach (['rules', 'modules'] as $what) {
        $this->asToken($portal)->getJson(($this->url)($what, query: '?summary=1'))->assertForbidden();
    }
});

it('never counts for another company or partner', function () {
    $c2 = orgToken(createMember($this->w->c2, MembershipType::Owner), $this->w->c2);
    $c4 = orgToken(createMember($this->w->c4, MembershipType::Owner), $this->w->c4);
    foreach ([$c2, $c4] as $token) {
        foreach (['rules', 'modules'] as $what) {
            expect($this->asToken($token)->getJson(($this->url)($what, query: '?summary=1'))->status())->toBeIn([403, 404]);
        }
    }
});
