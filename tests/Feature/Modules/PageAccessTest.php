<?php

use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerUserRole;

/*
 * FE-1: /api/me names the modules on here (the browser loads only their
 * screens) and the menu pages this person's roles do not give (left out of the
 * browser's routes), by the menu's own test. Off modules are not named; another
 * company's or partner's never show.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    toggles()->enable($this->w->g1, 'payroll', 'Test setup');
});

it('names the modules on here and nothing denied to the owner', function () {
    $me = $this->asToken(orgToken(createMember($this->w->c1, MembershipType::Owner), $this->w->c1))->getJson('/api/me')->assertOk()->json('data');

    expect($me['modules'])->toContain('hrm', 'payroll', 'attendance')
        ->and($me['modules'])->not->toContain('custom_reports')
        ->and($me['denied_pages'])->toBe([]);
});

it('leaves out the menu pages a role does not give', function () {
    $viewer = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view'], 'HR viewer'));
    $me = $this->asToken(orgToken($viewer, $this->w->c1))->getJson('/api/me')->assertOk()->json('data');

    expect($me['denied_pages'])->toContain('/hrm/fields', '/hrm/import')
        ->and($me['denied_pages'])->not->toContain('/hrm', '/hrm/departments')
        ->and($me['modules'])->toContain('hrm');

    // The menu agrees: what is denied is not in it.
    $menu = collect($this->asToken(orgToken($viewer, $this->w->c1))->getJson('/api/menu')->assertOk()->json('data.items'))
        ->flatMap(fn (array $item) => [$item['route'], ...array_column($item['children'], 'route')]);
    expect($menu->intersect($me['denied_pages'])->all())->toBe([]);
});

it('names only the modules of the organization worked in', function () {
    toggles()->enable($this->w->c4, 'crm', 'Other partner');
    $c1 = $this->asToken(orgToken(createMember($this->w->c1, MembershipType::Owner), $this->w->c1))->getJson('/api/me')->json('data.modules');
    $c4 = $this->asToken(orgToken(createMember($this->w->c4, MembershipType::Owner), $this->w->c4))->getJson('/api/me')->json('data.modules');

    expect($c4)->toContain('crm')->not->toContain('payroll')
        ->and($c1)->not->toContain('crm');
});

it('names no modules in the partner console', function () {
    $me = $this->asToken(partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA))->getJson('/api/me')->assertOk()->json('data');

    expect($me['modules'])->toBe([])->and($me['denied_pages'])->toBe([]);
});
