<?php

use App\Platform\Access\Models\Permission;
use App\Platform\Access\Models\RoleTemplate;
use App\Platform\Access\PermissionCatalog;
use App\Platform\Modules\Exceptions\InvalidModuleManifest;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Rules\RuleCatalog;

it('collects core, module and rule-editing permissions', function () {
    $catalog = app(PermissionCatalog::class);

    expect($catalog->keys())->toContain('roles.manage', 'payroll.run', 'crm.view', 'rules.edit.payroll', 'rules.edit.core')
        ->and($catalog->get('payroll.approve')->moduleKey)->toBe('payroll')
        ->and($catalog->get('roles.manage')->isCore())->toBeTrue()
        // Modules without rules get no rules.edit permission.
        ->and($catalog->has('rules.edit.crm'))->toBeFalse();
});

it('labels every permission in Bangla and English', function () {
    foreach (['en', 'bn'] as $locale) {
        foreach (app(PermissionCatalog::class)->all() as $permission) {
            expect($permission->label($locale))->not->toBe($permission->key, "{$permission->key} has no {$locale} label");
        }
    }
});

it('names every role template in Bangla and English', function () {
    foreach (['en', 'bn'] as $locale) {
        foreach (RoleTemplate::all() as $template) {
            expect($template->label($locale))->not->toStartWith('access.templates.', "{$template->key} has no {$locale} name");
        }
    }
});

it('expands template patterns', function () {
    $catalog = app(PermissionCatalog::class);

    expect($catalog->expand(['payroll.*', '!payroll.approve']))->toBe(['payroll.view', 'payroll.run'])
        ->and($catalog->expand(['*.view']))->toContain('hrm.view', 'crm.view')->not->toContain('hrm.manage')
        ->and($catalog->expand(['unknown.thing']))->toBe([]);
});

it('never puts both sides of a pair into a template', function () {
    $catalog = app(PermissionCatalog::class);
    $pairs = app(RuleCatalog::class)->get('access.separation_of_duties')->default;

    foreach (RoleTemplate::all() as $template) {
        $held = $catalog->expand($template->permissions);
        foreach ($pairs as $pair) {
            expect(in_array($pair['first'], $held, true) && in_array($pair['second'], $held, true))
                ->toBeFalse("{$template->key} holds {$pair['first']} and {$pair['second']}");
        }
    }
});

it('builds the separation-of-duties default from module manifests', function () {
    expect(app(RuleCatalog::class)->get('access.separation_of_duties')->default)
        ->toContain(['first' => 'payroll.run', 'second' => 'payroll.approve'])
        ->toContain(['first' => 'accounting.post', 'second' => 'accounting.approve'])
        ->and(app(RuleCatalog::class)->get('access.separation_of_duties')->needsApproval())->toBeTrue();
});

it('rejects malformed separation-of-duties pairs in a manifest', function (array $pairs) {
    $manifest = [
        'key' => 'demo', 'name' => 'demo::module.name', 'version' => '1.0.0', 'category' => 'business',
        'requires' => [], 'sectors' => ['*'], 'plans' => ['*'],
        'permissions' => ['demo.run', 'demo.approve'], 'separation_of_duties' => $pairs,
        'rules' => [], 'menu' => [], 'events' => [],
    ];

    ModuleRegistry::fromManifests([$manifest], app(PlanCatalog::class)->keys());
})->with([
    'same permission twice' => [[['demo.run', 'demo.run']]],
    'three entries' => [[['demo.run', 'demo.approve', 'demo.view']]],
    'nothing of this module' => [[['hrm.view', 'payroll.run']]],
])->throws(InvalidModuleManifest::class);

it('syncs permissions and templates and deprecates removed ones', function () {
    Permission::create(['key' => 'legacy.old', 'module_key' => 'legacy']);
    RoleTemplate::create(['key' => 'legacy_template', 'sector_key' => null, 'permissions' => []]);

    $this->artisan('access:sync')->assertSuccessful();

    expect(Permission::whereNull('deprecated_at')->count())->toBe(count(app(PermissionCatalog::class)->all()))
        ->and(Permission::where('key', 'legacy.old')->value('deprecated_at'))->not->toBeNull()
        ->and(RoleTemplate::where('key', 'legacy_template')->value('deprecated_at'))->not->toBeNull()
        ->and(RoleTemplate::where('key', 'principal')->value('sector_key'))->toBe('school');
});
