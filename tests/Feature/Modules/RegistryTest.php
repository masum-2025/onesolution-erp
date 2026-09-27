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
        ...$overrides,
    ];
}

it('loads every installed module from its manifest', function () {
    expect(app(ModuleRegistry::class)->keys())->toEqualCanonicalizing([
        'hrm', 'attendance', 'payroll', 'accounting', 'inventory', 'crm', 'factory_erp',
        'offline_mode', 'multi_currency', 'multi_language', 'api_integration', 'external_integrations',
        'document_ai', 'ai_assistant', 'energy_monitoring', 'carbon_management', 'advanced_audit', 'custom_reports', 'client_portal',
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
]);
