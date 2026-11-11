<?php

use App\Platform\Access\Models\Role;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessSeeder;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\DemoHealthSeeder;
use Database\Seeders\DemoHierarchySeeder;
use Database\Seeders\DemoModulesSeeder;
use Database\Seeders\HousePartnerSeeder;
use Database\Seeders\PackagingSeeder;
use Database\Seeders\RulesSeeder;
use Modules\Accounting\Charts\ChartTemplates;
use Modules\Diagnostics\Models\Test as LabTest;
use Modules\Diagnostics\Services\DiagnosticsStore;
use Modules\Inpatient\Models\Admission;
use Modules\Inpatient\Models\Bed;
use Modules\Inpatient\Services\InpatientStore;
use Modules\Outpatient\Models\Doctor;
use Modules\Outpatient\Services\OutpatientStore;
use Modules\Pharmacy\Models\Medicine;
use Modules\Pharmacy\Services\PharmacyStore;

/*
 * H-5 health sectors: a new pharmacy, diagnostic centre or hospital starts
 * with its modules, rules and roles (each role only what the job needs and
 * the menu that follows), books on its own chart with every posting key of
 * its modules on an account, the Starter plan offering the paid modules
 * instead of forcing them on, and the local demo hospital built once.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->groupOwner = createMember($this->w->g1, MembershipType::Owner, AccessScope::Descendants);
    $this->token = orgToken($this->groupOwner, $this->w->g1);
    $this->create = fn (string $sector) => $this->asToken($this->token)->postJson('/api/organizations', [
        'parent_id' => $this->w->g1->id, 'type' => 'company', 'name' => ['en' => "New {$sector}"], 'sector_key' => $sector,
    ])->assertCreated();
});

it('starts each health sector with its modules, rules and roles', function (string $sector, array $modules, array $roles, string $chart) {
    setPlan($this->w->g1, 'business');
    $response = ($this->create)($sector)->assertJsonPath('package.package.key', $sector);
    $company = Organization::findOrFail($response->json('data.id'));

    foreach ($modules as $module) {
        expect(resolvedModule($module, $company)->enabled)->toBeTrue("{$sector}: {$module} should be on");
    }
    expect(Role::where('organization_id', $company->id)->pluck('template_key')->sort()->values()->all())->toBe(collect($roles)->sort()->values()->all())
        ->and(ruleFor('accounting.chart_template', $company))->toBe($chart)
        ->and($response->json('package.rules_skipped') ?? [])->toBe([]);
})->with([
    'pharmacy' => ['pharmacy', ['inventory', 'pos', 'pharmacy', 'accounting'], ['administrator', 'manager', 'accountant', 'pharmacist', 'cashier', 'store_keeper'], 'pharmacy'],
    'diagnostic' => ['diagnostic', ['patients', 'patient_billing', 'diagnostics', 'accounting'], ['administrator', 'manager', 'accountant', 'receptionist', 'billing_officer', 'lab_technician', 'pathologist', 'hr_officer'], 'diagnostic'],
    'hospital' => ['hospital', ['patients', 'patient_billing', 'diagnostics', 'outpatient', 'inpatient', 'pharmacy', 'payroll'], ['administrator', 'manager', 'accountant', 'hr_officer', 'store_keeper', 'doctor', 'nurse', 'receptionist', 'billing_officer', 'lab_technician', 'pathologist', 'ward_incharge', 'pharmacist'], 'hospital'],
]);

it('lets doctors in a hospital see only the patients they care for', function () {
    setPlan($this->w->g1, 'business');
    $company = Organization::findOrFail(($this->create)('hospital')->json('data.id'));
    expect(ruleFor('patients.doctor_sees', $company))->toBe('own');
});

it('gives each health role only what the job needs, and the menu follows', function () {
    setPlan($this->w->g1, 'business');
    $company = Organization::findOrFail(($this->create)('hospital')->json('data.id'));
    $role = fn (string $key) => Role::where('organization_id', $company->id)->where('template_key', $key)->sole();

    expect($role('doctor')->permissionKeys())->toContain('outpatient.consult', 'inpatient.round', 'diagnostics.order')
        ->not->toContain('patient_billing.collect', 'inpatient.discharge', 'diagnostics.verify', 'pharmacy.sell')
        ->and($role('nurse')->permissionKeys())->toContain('inpatient.nurse')->not->toContain('inpatient.round', 'outpatient.consult')
        ->and($role('lab_technician')->permissionKeys())->toContain('diagnostics.result')->not->toContain('diagnostics.verify')
        ->and($role('pathologist')->permissionKeys())->toContain('diagnostics.verify')
        ->and($role('pharmacist')->permissionKeys())->toContain('pharmacy.sell', 'inventory.manage')->not->toContain('inventory.approve', 'pharmacy.approve')
        ->and($role('receptionist')->permissionKeys())->toContain('patients.register', 'outpatient.book', 'inpatient.admit')->not->toContain('patients.treat');

    $menuOf = function (string $template) use ($company, $role) {
        $person = staffWithRoles($company, $role($template));

        return collect($this->asToken(orgToken($person, $company))->getJson('/api/menu')->assertOk()->json('data'))->pluck('key')->all();
    };
    expect($menuOf('nurse'))->toContain('inpatient')->not->toContain('pharmacy', 'patient_billing', 'accounting')
        ->and($menuOf('lab_technician'))->toContain('diagnostics')->not->toContain('inpatient', 'outpatient', 'accounting')
        ->and($menuOf('pharmacist'))->toContain('pharmacy')->not->toContain('inpatient', 'diagnostics');
});

it('puts every posting key of the health modules on an account of the chart', function (string $chart, array $modules) {
    $templates = app(ChartTemplates::class);
    $codes = collect($templates->accounts($chart))->pluck('code')->map(fn ($code) => (string) $code)->all();
    $postings = $templates->postings($chart);
    $registry = app(ModuleRegistry::class);
    foreach ($modules as $module) {
        foreach (array_keys($registry->get($module)->ledgerAccounts) as $key) {
            expect(array_key_exists($key, $postings))->toBeTrue("{$chart}: {$key} has no account");
        }
    }
    foreach ($postings as $key => $code) {
        expect(in_array((string) $code, $codes, true))->toBeTrue("{$chart}: {$key} points at {$code}, not in the chart");
    }
})->with([
    'pharmacy' => ['pharmacy', ['pos', 'inventory']],
    'diagnostic' => ['diagnostic', ['patient_billing', 'diagnostics']],
    'hospital' => ['hospital', ['patient_billing', 'diagnostics', 'pos', 'inventory']],
]);

it('sets up a hospital\'s books on the hospital chart', function () {
    setPlan($this->w->g1, 'business');
    $company = Organization::findOrFail(($this->create)('hospital')->json('data.id'));
    $owner = createMember($company);
    $setup = $this->asToken(orgToken($owner, $company))->postJson("/api/organizations/{$company->id}/accounting/setup", [])->assertCreated()->json('data');
    expect($setup['template'])->toBe('hospital');
});

it('offers the paid health modules on the Starter plan instead of forcing them on', function () {
    $response = ($this->create)('hospital');
    $company = Organization::findOrFail($response->json('data.id'));
    expect(collect($response->json('package.modules_upgrade'))->pluck('key')->all())->toContain('inpatient', 'outpatient', 'diagnostics', 'pharmacy')
        ->and(resolvedModule('inpatient', $company)->enabled)->toBeFalse();
});

it('builds the demo hospital once', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 04:00:00', 'UTC'));
    $this->seed([HousePartnerSeeder::class, RulesSeeder::class, CountriesSeeder::class, AccessSeeder::class, PackagingSeeder::class, DemoHierarchySeeder::class, DemoModulesSeeder::class]);
    $this->seed(DemoHealthSeeder::class);
    $find = fn () => Organization::query()->get()->filter(fn (Organization $org) => $org->name === DemoHealthSeeder::HOSPITAL);
    $hospital = $find()->sole();

    expect($hospital->sector_key)->toBe('hospital')
        ->and(app(PharmacyStore::class)->query(Medicine::class, $hospital)->count())->toBe(100)
        ->and(app(DiagnosticsStore::class)->query(LabTest::class, $hospital)->count())->toBe(20)
        ->and(app(OutpatientStore::class)->query(Doctor::class, $hospital)->count())->toBe(3)
        ->and(app(InpatientStore::class)->query(Bed::class, $hospital)->count())->toBe(12)
        ->and(app(InpatientStore::class)->query(Admission::class, $hospital)->where('status', 'admitted')->count())->toBe(1)
        ->and(Role::where('organization_id', $hospital->id)->where('template_key', 'doctor')->exists())->toBeTrue();

    $this->seed(DemoHealthSeeder::class);
    expect($find())->toHaveCount(1)->and(app(PharmacyStore::class)->query(Medicine::class, $hospital)->count())->toBe(100);
});
