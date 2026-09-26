<?php

use App\Platform\Access\Models\Role;
use App\Platform\Audit\AuditLog;
use App\Platform\Packaging\Models\OrganizationPackage;
use App\Platform\Packaging\SectorCatalog;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleTargets;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;

/*
 * Phase 5 onboarding: a new company gets its sector package (modules, rules,
 * roles) as ordinary, editable company data.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->groupOwner = createMember($this->w->g1, MembershipType::Owner, AccessScope::Descendants);
    $this->token = orgToken($this->groupOwner, $this->w->g1);
});

function createCompanyViaApi(object $test, string $sector, string $name = 'New School')
{
    return $test->asToken($test->token)->postJson('/api/organizations', [
        'parent_id' => $test->w->g1->id,
        'type' => 'company',
        'name' => ['en' => $name],
        'sector_key' => $sector,
    ]);
}

it('gives a new school company its modules, roles and rules automatically', function () {
    $response = createCompanyViaApi($this, 'school')->assertCreated()
        ->assertJsonPath('package.package.key', 'school')
        ->assertJsonPath('package.package.name', 'School');

    $company = Organization::findOrFail($response->json('data.id'));

    foreach (['hrm', 'attendance', 'payroll', 'accounting'] as $module) {
        expect(resolvedModule($module, $company)->enabled)->toBeTrue("{$module} should be on")
            ->and(resolvedModule($module, $company)->source)->toBe('self');
    }

    expect(Role::where('organization_id', $company->id)->pluck('template_key')->sort()->values()->all())
        ->toBe(['accountant', 'finance_approver', 'office_staff', 'principal', 'teacher'])
        ->and(Role::where('organization_id', $company->id)->where('template_key', 'principal')->first()->name)
        ->toEqual(['en' => 'Principal', 'bn' => 'প্রধান শিক্ষক'])
        ->and(ruleFor('attendance.late_grace_minutes', $company))->toBe(10)
        ->and(collect($response->json('package.rules_set'))->pluck('key')->all())->toContain('attendance.late_grace_minutes');

    // Editable afterwards: the company changes the default like any of its own values.
    orgRule($company, 'attendance.late_grace_minutes', 15);
    expect(ruleFor('attendance.late_grace_minutes', $company))->toBe(15);

    expect(AuditLog::where('action', 'organization.package_applied')->where('organization_id', $company->id)->exists())->toBeTrue();
});

it('lists modules the plan does not include instead of forcing them on', function () {
    app()->instance(SectorCatalog::class, new SectorCatalog([
        ['key' => 'school', 'modules' => ['hrm', 'custom_reports'], 'rules' => [], 'role_templates' => []],
    ]));

    $response = createCompanyViaApi($this, 'school')->assertCreated();
    $company = Organization::findOrFail($response->json('data.id'));

    // The group is on the default Starter plan, which has no custom reports.
    expect($response->json('package.modules_upgrade.0.key'))->toBe('custom_reports')
        ->and(resolvedModule('custom_reports', $company)->enabled)->toBeFalse()
        ->and(resolvedModule('hrm', $company)->enabled)->toBeTrue();
});

it('records what it could not apply and why', function () {
    // The group locks the grace period, so the company value cannot be set.
    toggles()->enable($this->w->g1, 'attendance', 'Group-wide attendance');
    orgRule($this->w->g1, 'attendance.late_grace_minutes', 20, RuleMode::Lock);

    $response = createCompanyViaApi($this, 'school')->assertCreated();

    expect($response->json('package.skipped'))->toContain(['key' => 'attendance.late_grace_minutes', 'reason' => 'locked_by_parent'])
        ->and(ruleFor('attendance.late_grace_minutes', Organization::findOrFail($response->json('data.id'))))->toBe(20);
});

it('applies a package once', function () {
    $company = Organization::findOrFail(createCompanyViaApi($this, 'school')->json('data.id'));
    $roles = Role::where('organization_id', $company->id)->count();

    $this->asToken($this->token)->postJson("/api/organizations/{$company->id}/sector-package")
        ->assertOk()
        ->assertJsonPath('message', 'This sector package was applied before; nothing changed.');

    expect(Role::where('organization_id', $company->id)->count())->toBe($roles)
        ->and(OrganizationPackage::where('organization_id', $company->id)->count())->toBe(1);
});

it('applies the package of a new sector on request and never overwrites own values', function () {
    toggles()->enable($this->w->c3, 'attendance', 'Attendance first');
    orgRule($this->w->c3, 'attendance.late_grace_minutes', 30);
    $this->w->c3->forceFill(['sector_key' => 'factory'])->save();
    $owner = createMember($this->w->c3);

    $this->asToken(orgToken($owner, $this->w->c3))->postJson("/api/organizations/{$this->w->c3->id}/sector-package")
        ->assertCreated()
        ->assertJsonPath('data.package.key', 'factory')
        ->assertJsonPath('message', 'The sector package was applied.');

    expect(ruleFor('attendance.late_grace_minutes', $this->w->c3))->toBe(30)
        ->and(Role::where('organization_id', $this->w->c3->id)->where('template_key', 'hr_officer')->exists())->toBeTrue();
});

it('needs permission to apply a package', function () {
    $staff = createMember($this->w->c1, MembershipType::Staff);

    $this->asToken(orgToken($staff, $this->w->c1))->postJson("/api/organizations/{$this->w->c1->id}/sector-package")->assertForbidden();
});

it('accepts only known sectors', function () {
    createCompanyViaApi($this, 'spaceport')->assertUnprocessable()->assertJsonValidationErrors('sector_key');
});

it('takes a new sector from data alone', function () {
    // A new sector is one more data entry (+ translations); no code changes.
    app()->instance(SectorCatalog::class, new SectorCatalog([
        ...require database_path('seeders/data/sector-packages.php'),
        ['key' => 'clinic', 'modules' => ['hrm', 'attendance'], 'rules' => [['key' => 'hrm.probation_days', 'value' => 90]], 'role_templates' => ['manager', 'staff']],
    ]));

    $response = createCompanyViaApi($this, 'clinic', 'City Clinic')->assertCreated()->assertJsonPath('package.package.key', 'clinic');
    $clinic = Organization::findOrFail($response->json('data.id'));

    expect(resolvedModule('attendance', $clinic)->enabled)->toBeTrue()
        ->and(ruleFor('hrm.probation_days', $clinic))->toBe(90)
        ->and(Role::where('organization_id', $clinic->id)->count())->toBe(2);
});

it('lists sectors and plans in both languages', function () {
    $sectors = $this->asToken($this->token)->withHeader('X-Locale', 'bn')->getJson('/api/sectors')->assertOk()->json('data');
    expect(collect($sectors)->firstWhere('key', 'school')['name'])->toBe('স্কুল');

    $plans = collect($this->asToken($this->token)->getJson('/api/plans')->assertOk()->json('data'))->keyBy('key');
    expect($plans->keys()->all())->toBe(['starter', 'business', 'enterprise'])
        ->and($plans['starter']['prices'])->toContain(['currency' => 'BDT', 'period' => 'monthly', 'amount_minor' => 150000])
        ->and(collect($plans['starter']['modules'])->pluck('key'))->not->toContain('custom_reports')
        ->and(collect($plans['business']['modules'])->pluck('key'))->toContain('custom_reports');
});

it('keeps sector data files consistent with modules, rules and templates', function () {
    $registry = app(App\Platform\Modules\ModuleRegistry::class);
    $rules = app(App\Platform\Rules\RuleCatalog::class);
    $templates = App\Platform\Access\Models\RoleTemplate::pluck('key')->all();

    foreach (app(SectorCatalog::class)->all() as $package) {
        foreach ($package->modules as $module) {
            expect($registry->has($module))->toBeTrue("{$package->key}: unknown module {$module}");
        }
        foreach ($package->rules as $rule) {
            expect($rules->has($rule['key']))->toBeTrue("{$package->key}: unknown rule {$rule['key']}")
                ->and($rules->get($rule['key'])->allowsLevel(App\Platform\Rules\Enums\RuleScope::Company))->toBeTrue("{$package->key}: {$rule['key']} not settable per company");
        }
        foreach ($package->roleTemplates as $template) {
            expect($templates)->toContain($template);
        }
        expect(__("packaging.sectors.{$package->key}.name", [], 'bn'))->not->toStartWith('packaging.');
    }

    foreach (app(App\Platform\Packaging\PlanCatalog::class)->all() as $plan) {
        foreach ($plan->modules as $module) {
            expect($module === '*' || $registry->has($module))->toBeTrue("{$plan->key}: unknown module {$module}");
        }
    }
});

it('syncs plans and packages into the database', function () {
    $this->artisan('packaging:sync')->assertSuccessful();

    expect(App\Platform\Packaging\Models\Plan::where('key', 'business')->first()->prices()->count())->toBe(4)
        ->and(App\Platform\Packaging\Models\SectorPackage::where('key', 'school')->value('version'))
        ->toBe(app(SectorCatalog::class)->get('school')->version());
});

it('never lets a company set its own plan', function () {
    $owner = createMember($this->w->c1);

    $this->asToken(orgToken($owner, $this->w->c1))->patchJson("/api/organizations/{$this->w->c1->id}", ['plan_key' => 'enterprise'])
        ->assertUnprocessable()->assertJsonValidationErrors('plan_key');

    // Limits at client level are the partner's deal to write, never the client's.
    $token = orgToken($owner, $this->w->c1);
    $this->asToken($token)->putJson("/api/organizations/{$this->w->c1->id}/rules/plans.max_users", ['mode' => 'set', 'value' => 999, 'reason' => 'More seats please'])
        ->assertForbidden();

    $rule = collect($this->asToken($token)->getJson("/api/organizations/{$this->w->c1->id}/rules?module=core")->json('data.0.categories'))
        ->flatMap(fn ($category) => $category['rules'])->firstWhere('key', 'plans.max_users');
    expect($rule['edit_blocked_by'])->toBe('set_by_provider');
});
