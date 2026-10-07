<?php

use App\Platform\Modules\Http\Controllers\MenuController;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\MembershipType;

beforeEach(function () {
    $this->w = tenancyWorld();
    toggles()->enable($this->w->c1, 'hrm', 'Start HR');
    $this->owner = createMember($this->w->c1, MembershipType::Owner);
});

it('groups module entries in sections with their sub-pages and quick actions', function () {
    $response = $this->asToken(orgToken($this->owner, $this->w->c1))->getJson('/api/menu')->assertOk();
    $hrm = collect($response->json('data'))->firstWhere('module', 'hrm');

    expect($hrm['section'])->toBe('people')
        ->and($hrm['section_label'])->toBe('People')
        ->and($hrm['icon'])->toBe('users')
        ->and(array_column($hrm['children'], 'key'))->toBe(['dashboard', 'employees', 'positions', 'org_chart', 'fields', 'import', 'settings'])
        ->and($hrm['children'][0])->toBe(['key' => 'dashboard', 'label' => 'Dashboard', 'route' => '/m/hrm'])
        ->and($hrm['children'][1])->toBe(['key' => 'employees', 'label' => 'Employees', 'route' => '/hrm'])
        ->and($hrm['children'][6])->toBe(['key' => 'settings', 'label' => 'Settings', 'route' => '/m/hrm/settings'])
        ->and($response->json('quick_actions'))->toBe([
            ['module' => 'hrm', 'key' => 'hire', 'label' => 'New employee', 'route' => '/hrm/new', 'icon' => 'user-plus'],
        ]);
});

it('shows only the sub-pages and quick actions the person may use', function () {
    $viewer = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view'], 'HR viewer'));

    $response = $this->asToken(orgToken($viewer, $this->w->c1))->getJson('/api/menu')->assertOk();
    $hrm = collect($response->json('data'))->firstWhere('module', 'hrm');

    expect(array_column($hrm['children'], 'key'))->toBe(['dashboard', 'employees', 'positions', 'org_chart', 'settings'])
        ->and($response->json('quick_actions'))->toBe([]);
});

it('offers nothing to create while the organization is read-only', function () {
    actInOrganization($this->owner, $this->w->c1)->restrict(CurrentContext::MODE_READ_ONLY, 'support');

    $response = app()->call(MenuController::class);

    expect($response->getData(true)['quick_actions'])->toBe([])
        ->and(collect($response->getData(true)['data'])->pluck('module'))->toContain('hrm');
});

it('leaves out sub-pages and quick actions of a module that is off', function () {
    toggles()->disable($this->w->c1, 'hrm', 'Stop HR');

    $response = $this->asToken(orgToken($this->owner, $this->w->c1))->getJson('/api/menu')->assertOk();

    expect(collect($response->json('data'))->pluck('module'))->not->toContain('hrm')
        ->and($response->json('quick_actions'))->toBe([]);
});

it('translates sections, sub-pages and quick actions', function () {
    $this->w->g1->update(['default_locale' => 'bn']);

    $response = $this->asToken(orgToken(withoutOwnLanguage($this->owner), $this->w->c1))->getJson('/api/menu')->assertOk();
    $hrm = collect($response->json('data'))->firstWhere('module', 'hrm');

    expect($hrm['section_label'])->toBe('মানুষ')
        ->and($hrm['children'][0]['label'])->toBe('ড্যাশবোর্ড')
        ->and($hrm['children'][1]['label'])->toBe('কর্মী')
        ->and($response->json('quick_actions.0.label'))->toBe('নতুন কর্মী');
});

it('does not show one organization\'s modules in another\'s menu', function () {
    $other = createMember($this->w->c4, MembershipType::Owner);

    $response = $this->asToken(orgToken($other, $this->w->c4))->getJson('/api/menu')->assertOk();

    expect(collect($response->json('data'))->pluck('module'))->not->toContain('hrm')
        ->and($response->json('quick_actions'))->toBe([]);
});

it('gives every module entry a dashboard and settings, also without its own sub-pages', function () {
    toggles()->enable($this->w->c1, 'api_integration', 'Integrations');

    $crm = collect($this->asToken(orgToken($this->owner, $this->w->c1))->getJson('/api/menu')->json('data'))->firstWhere('module', 'api_integration');

    expect(array_column($crm['children'], 'route'))->toBe(['/m/api_integration', '/m/api_integration/settings']);
});
