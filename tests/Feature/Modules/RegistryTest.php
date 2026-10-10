<?php

use App\Platform\Modules\Exceptions\InvalidModuleManifest;
use App\Platform\Modules\ModuleRegistry;

function manifest(string $key, array $overrides = []): array
{
    return [
        'key' => $key,
        'name' => "{$key}::module.name",
        'version' => '0.1.0',
        'category' => 'business',
        'requires' => [],
        'sectors' => ['*'],
        'plans' => ['*'],
        'permissions' => [],
        'dashboard' => ['widgets' => []],
        'settings' => ['pages' => []],
        ...$overrides,
    ];
}

it('loads every installed module from its manifest', function () {
    expect(app(ModuleRegistry::class)->keys())->toEqualCanonicalizing([
        'hrm', 'attendance', 'payroll', 'accounting', 'inventory', 'pos', 'crm', 'factory_erp', 'education', 'course_registration',
        'offline_mode', 'multi_currency', 'multi_language', 'api_integration', 'external_integrations',
        'document_ai', 'ai_assistant', 'energy_monitoring', 'carbon_management', 'advanced_audit', 'custom_reports', 'client_portal', 'online_payments',
    ]);
});

it('orders every module after the modules it requires', function () {
    $registry = app(ModuleRegistry::class);
    $position = array_flip($registry->keys());

    foreach ($registry->all() as $key => $module) {
        foreach ($module->requires as $required) {
            expect($position[$required])->toBeLessThan($position[$key]);
        }
    }
});

it('knows the declared dependencies', function () {
    $registry = app(ModuleRegistry::class);

    expect($registry->dependenciesOf('payroll'))->toEqualCanonicalizing(['hrm', 'attendance'])
        ->and($registry->dependenciesOf('factory_erp'))->toEqualCanonicalizing(['inventory', 'accounting'])
        ->and($registry->dependenciesOf('multi_currency'))->toBe(['accounting'])
        ->and($registry->dependenciesOf('carbon_management'))->toBe(['energy_monitoring'])
        ->and($registry->dependenciesOf('external_integrations'))->toBe(['api_integration'])
        ->and($registry->dependentsOf('hrm'))->toEqualCanonicalizing(['attendance', 'payroll']);
});

it('translates module names', function () {
    expect(app(ModuleRegistry::class)->get('payroll')->label('bn'))->toBe('বেতন')
        ->and(app(ModuleRegistry::class)->get('payroll')->label('en'))->toBe('Payroll');
});

it('rejects a dependency cycle', function () {
    ModuleRegistry::fromManifests([
        manifest('alpha', ['requires' => ['beta']]),
        manifest('beta', ['requires' => ['gamma']]),
        manifest('gamma', ['requires' => ['alpha']]),
    ], ['starter']);
})->throws(InvalidModuleManifest::class, 'dependency cycle');

it('rejects invalid manifests', function (array $manifests, string $problem) {
    expect(fn () => ModuleRegistry::fromManifests($manifests, ['starter']))
        ->toThrow(InvalidModuleManifest::class, $problem);
})->with([
    'unknown dependency' => [[manifest('alpha', ['requires' => ['ghost']])], 'unknown module'],
    'unknown plan' => [[manifest('alpha', ['plans' => ['platinum']])], 'unknown plan'],
    'foreign permission' => [[manifest('alpha', ['permissions' => ['beta.view']])], 'must start with'],
    'duplicate key' => [[manifest('alpha'), manifest('alpha')], 'duplicate key'],
    'bad key' => [[manifest('Alpha Module')], 'snake_case'],
    'self dependency' => [[manifest('alpha', ['requires' => ['alpha']])], 'cannot require itself'],
    'no sectors' => [[manifest('alpha', ['sectors' => []])], 'must not be empty'],
    'menu permission not declared' => [[manifest('alpha', ['permissions' => ['alpha.view'], 'menu' => [
        ['key' => 'a', 'label' => 'x', 'route' => '/a', 'order' => 1, 'permission' => 'alpha.manage'],
    ]])], 'not one of the module'],
    'menu child without route' => [[manifest('alpha', ['permissions' => ['alpha.view'], 'menu' => [
        ['key' => 'a', 'label' => 'x', 'route' => '/a', 'order' => 1, 'children' => [['key' => 'b', 'label' => 'y']]],
    ]])], 'menu child is missing route'],
    'bad section' => [[manifest('alpha', ['menu' => [['key' => 'a', 'label' => 'x', 'route' => '/a', 'order' => 1, 'section' => 'Money Movement']]])], 'section'],
    'quick action without permission' => [[manifest('alpha', ['permissions' => ['alpha.view'], 'quick_actions' => [
        ['key' => 'new', 'label' => 'x', 'route' => '/a/new'],
    ]])], 'quick action permission'],
    'attention not a list' => [[manifest('alpha', ['attention' => ['x' => 'Foo']])], 'attention must be a list'],
    'no dashboard' => [[manifest('alpha', ['dashboard' => null])], 'dashboard.widgets is required'],
    'no settings' => [[manifest('alpha', ['settings' => []])], 'settings.pages is required'],
    'unknown widget type' => [[manifest('alpha', ['permissions' => ['alpha.view'], 'dashboard' => ['widgets' => [['type' => 'pie'] + ['key' => 'count', 'label' => 'x', 'type' => 'stat', 'provider' => 'X', 'permission' => 'alpha.view']]]])], 'type [pie] is unknown'],
    'widget without permission' => [[manifest('alpha', ['permissions' => ['alpha.view'], 'dashboard' => ['widgets' => [array_diff_key(['key' => 'count', 'label' => 'x', 'type' => 'stat', 'provider' => 'X', 'permission' => 'alpha.view'], ['permission' => 1])]]])], 'dashboard widget permission'],
    'widget without provider' => [[manifest('alpha', ['permissions' => ['alpha.view'], 'dashboard' => ['widgets' => [array_diff_key(['key' => 'count', 'label' => 'x', 'type' => 'stat', 'provider' => 'X', 'permission' => 'alpha.view'], ['provider' => 1])]]])], 'missing provider'],
    'duplicate widget' => [[manifest('alpha', ['permissions' => ['alpha.view'], 'dashboard' => ['widgets' => [['key' => 'count', 'label' => 'x', 'type' => 'stat', 'provider' => 'X', 'permission' => 'alpha.view'], ['key' => 'count', 'label' => 'x', 'type' => 'stat', 'provider' => 'X', 'permission' => 'alpha.view']]]])], 'unique'],
    'bad widget size' => [[manifest('alpha', ['permissions' => ['alpha.view'], 'dashboard' => ['widgets' => [['size' => 4] + ['key' => 'count', 'label' => 'x', 'type' => 'stat', 'provider' => 'X', 'permission' => 'alpha.view']]]])], 'size must be'],
    'settings page without route' => [[manifest('alpha', ['settings' => ['pages' => [['key' => 'a', 'label' => 'x']]]])], 'settings page is missing route'],
    'notification of another module' => [[manifest('alpha', ['notifications' => ['beta.sent' => ['channels' => ['mail'], 'placeholders' => [], 'audience' => 'client', 'path' => '/a']]])], 'notification [beta.sent]'],
    'notification with an unknown channel' => [[manifest('alpha', ['notifications' => ['alpha.sent' => ['channels' => ['fax'], 'placeholders' => [], 'audience' => 'client', 'path' => '/a']]])], 'notification [alpha.sent]'],
    'notifications as a list' => [[manifest('alpha', ['notifications' => [['channels' => ['mail']]]])], 'keyed by notification key'],
    'ledger account of another module' => [[manifest('alpha', ['ledger_accounts' => ['beta.cash' => ['label' => 'x', 'type' => 'asset']]])], 'ledger account [beta.cash]'],
    'ledger account of an unknown type' => [[manifest('alpha', ['ledger_accounts' => ['alpha.cash' => ['label' => 'x', 'type' => 'money']]])], 'ledger account [alpha.cash]'],
    'ledger accounts as a list' => [[manifest('alpha', ['ledger_accounts' => [['label' => 'x', 'type' => 'asset']]])], 'keyed by posting key'],
]);

it('reads sections, sub-pages and quick actions from a manifest', function () {
    $module = ModuleRegistry::fromManifests([manifest('alpha', [
        'permissions' => ['alpha.view', 'alpha.manage'],
        'menu' => [['key' => 'a', 'label' => 'x', 'route' => '/a', 'order' => 1, 'section' => 'people', 'children' => [
            ['key' => 'list', 'label' => 'y', 'route' => '/a', 'permission' => 'alpha.view'],
        ]]],
        'quick_actions' => [['key' => 'new', 'label' => 'z', 'route' => '/a/new', 'permission' => 'alpha.manage']],
    ])], ['starter'])->get('alpha');

    expect($module->menu[0]['children'])->toHaveCount(1)
        ->and($module->quickActions[0]['permission'])->toBe('alpha.manage')
        ->and($module->attention)->toBe([]);
});

it('gives every installed module a dashboard and a settings page', function () {
    foreach (app(ModuleRegistry::class)->all() as $module) {
        foreach ($module->widgets as $widget) {
            expect(class_exists($widget['provider']))->toBeTrue("{$module->key}: {$widget['provider']}");
        }
    }

    expect(app(ModuleRegistry::class)->get('hrm')->widgets)->toHaveCount(6);
});
