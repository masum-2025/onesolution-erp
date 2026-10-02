<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\Enums\RuleScope;
use App\Platform\Rules\Enums\RuleValueStatus;
use App\Platform\Rules\Exceptions\RuleException;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Rules\Models\RuleValueHistory;
use App\Platform\Rules\RuleCache;
use App\Platform\Rules\RuleCatalog;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Rules\RuleTargets;
use App\Platform\Rules\RuleValueValidator;
use App\Platform\Tenancy\Actions\CreateOrganization;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Exceptions\HierarchyViolation;

beforeEach(function () {
    $this->w = tenancyWorld();
    foreach (['payroll', 'inventory'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
});

function ruleError(Closure $callback): string
{
    try {
        $callback();
    } catch (RuleException $e) {
        return $e->errorCode();
    }

    return 'no error';
}

it('uses the rule default when nobody set a value', function () {
    expect(ruleFor('attendance.late_grace_minutes', $this->w->b1))->toBe(10)
        ->and(app(RuleResolver::class)->resolve('attendance.late_grace_minutes', app(RuleContextFactory::class)->forOrganization($this->w->b1))->sourceLevel)->toBeNull();
});

it('rejects a branch value outside the group constraint, with a clear message', function () {
    orgRule($this->w->g1, 'attendance.late_grace_minutes', ['max' => 15], RuleMode::Constrain);

    try {
        orgRule($this->w->b1, 'attendance.late_grace_minutes', 30);
        $this->fail('Expected a constraint violation.');
    } catch (RuleException $e) {
        expect($e->errorCode())->toBe('violates_constraint')
            ->and($e->render()->getData(true)['message'])
            ->toBe('The value for "Late grace period (minutes)" is outside the limits set by G1. Choose a value inside those limits.');
    }

    orgRule($this->w->b1, 'attendance.late_grace_minutes', 12);
    expect(ruleFor('attendance.late_grace_minutes', $this->w->b1))->toBe(12);
});

it('locks a value so no descendant can change it', function () {
    $targets = app(RuleTargets::class);
    // valuation_method is sensitive; trusted writes stand in for approved changes here.
    ruleService()->set($targets->organization($this->w->c1), 'inventory.valuation_method', RuleMode::Set, 'weighted_average', 'Company choice', trusted: true);
    ruleService()->set($targets->organization($this->w->g1), 'inventory.valuation_method', RuleMode::Lock, 'FIFO', 'Group policy', trusted: true);

    expect(ruleFor('inventory.valuation_method', $this->w->c1))->toBe('FIFO')
        ->and(ruleFor('inventory.valuation_method', $this->w->b1))->toBe('FIFO')
        ->and(ruleError(fn () => ruleService()->set($targets->organization($this->w->c1), 'inventory.valuation_method', RuleMode::Set, 'weighted_average', 'Try again', trusted: true)))
        ->toBe('locked_by_parent');
});

it('lets a branch override its company when nothing is locked or constrained', function () {
    orgRule($this->w->c1, 'attendance.late_grace_minutes', 15);
    orgRule($this->w->b1, 'attendance.late_grace_minutes', 5);

    expect(ruleFor('attendance.late_grace_minutes', $this->w->c1))->toBe(15)
        ->and(ruleFor('attendance.late_grace_minutes', $this->w->b1))->toBe(5)
        ->and(ruleFor('attendance.late_grace_minutes', $this->w->d1))->toBe(5);
});

it('falls back to the inherited value after a reset', function () {
    orgRule($this->w->c1, 'attendance.late_grace_minutes', 15);
    orgRule($this->w->b1, 'attendance.late_grace_minutes', 5);

    ruleService()->reset(app(RuleTargets::class)->organization($this->w->b1), 'attendance.late_grace_minutes', 'Follow the company');

    expect(ruleFor('attendance.late_grace_minutes', $this->w->b1))->toBe(15)
        ->and(ruleError(fn () => ruleService()->reset(app(RuleTargets::class)->organization($this->w->b1), 'attendance.late_grace_minutes', 'Again')))
        ->toBe('nothing_to_reset');
});

it('explains the full resolution trace', function () {
    platformRule('attendance.late_grace_minutes', 20);
    orgRule($this->w->g1, 'attendance.late_grace_minutes', ['max' => 30], RuleMode::Constrain);
    orgRule($this->w->c1, 'attendance.late_grace_minutes', 15);

    $resolved = app(RuleResolver::class)->explain('attendance.late_grace_minutes', app(RuleContextFactory::class)->forOrganization($this->w->d1));
    $levels = array_column($resolved->trace, 'level');

    expect($levels)->toBe(['default', 'platform', 'partner', 'plan', 'group', 'company', 'branch', 'department'])
        ->and($resolved->trace[1]['set'])->toBe(20)
        ->and($resolved->trace[4]['constrain'])->toBe(['max' => 30])
        ->and($resolved->value)->toBe(15)
        ->and($resolved->sourceLevel)->toBe('company')
        ->and($resolved->sourceName)->toBe('C1')
        ->and($resolved->constraints)->toBe(['max' => 30]);
});

it('applies a future-dated payroll value only from its date, and keeps the past', function () {
    $this->freezeTime();
    $lastMonth = now()->subMonth();

    orgRule($this->w->c1, 'payroll.overtime_multiplier', '2.0');
    ruleService()->set(
        app(RuleTargets::class)->organization($this->w->c1), 'payroll.overtime_multiplier', RuleMode::Set, '2.5',
        'New agreement from next month', effectiveFrom: now()->addMonth(),
    );

    $context = app(RuleContextFactory::class)->forOrganization($this->w->c1);
    $resolver = app(RuleResolver::class);

    expect($resolver->get('payroll.overtime_multiplier', $context))->toBe('2.0')
        ->and($resolver->get('payroll.overtime_multiplier', $context, now()->addMonth()->addDay()))->toBe('2.5')
        ->and($resolver->get('payroll.overtime_multiplier', $context, $lastMonth))->toBe('1.5');

    $this->travel(32)->days();
    expect(ruleFor('payroll.overtime_multiplier', $this->w->c1))->toBe('2.5');
});

it('needs a second person to approve a sensitive change', function () {
    $maker = User::factory()->create();
    $checker = User::factory()->create();

    $pending = orgRule($this->w->c1, 'payroll.salary_approval_levels', 2, actor: $maker);

    expect($pending->status)->toBe(RuleValueStatus::PendingApproval)
        ->and(ruleFor('payroll.salary_approval_levels', $this->w->c1))->toBe(1)
        ->and(ruleError(fn () => ruleService()->approve($pending, $maker)))->toBe('self_approval');

    ruleService()->approve($pending->fresh(), $checker, 'Checked with finance');

    expect(ruleFor('payroll.salary_approval_levels', $this->w->c1))->toBe(2)
        ->and(AuditLog::where('action', 'rule.approved')->sole()->actor_user_id)->toBe($checker->id);
});

it('can reject a pending change', function () {
    $pending = orgRule($this->w->c1, 'payroll.salary_approval_levels', 3, actor: User::factory()->create());

    ruleService()->reject($pending, User::factory()->create(), 'Not agreed');

    expect($pending->fresh()->status)->toBe(RuleValueStatus::Rejected)
        ->and(ruleFor('payroll.salary_approval_levels', $this->w->c1))->toBe(1);
});

it('invalidates cached values of every descendant when a parent changes', function () {
    expect(ruleFor('attendance.late_grace_minutes', $this->w->d1))->toBe(10);

    orgRule($this->w->g1, 'attendance.late_grace_minutes', 25);
    expect(ruleFor('attendance.late_grace_minutes', $this->w->d1))->toBe(25);

    platformRule('attendance.late_grace_minutes', 40);
    expect(ruleFor('attendance.late_grace_minutes', $this->w->d1))->toBe(25)
        ->and(ruleFor('attendance.late_grace_minutes', $this->w->c3))->toBe(40);
});

it('lets a new module add rules through its manifest alone', function () {
    $registry = ModuleRegistry::fromManifests([[
        'key' => 'fleet',
        'name' => 'fleet::module.name',
        'version' => '0.1.0',
        'category' => 'business',
        'sectors' => ['*'],
        'plans' => ['*'],
        'rules' => [[
            'key' => 'fleet.max_trip_hours',
            'type' => 'integer',
            'schema' => ['minimum' => 1, 'maximum' => 24],
            'default' => 8,
            'label' => 'fleet::rules.max_trip_hours.label',
            'description' => 'fleet::rules.max_trip_hours.description',
            'overridable_levels' => ['platform', 'company'],
        ]],
    ]], app(PlanCatalog::class)->keys());

    app()->instance(RuleCatalog::class, RuleCatalog::fromModules($registry, app(RuleValueValidator::class)));
    app()->forgetScopedInstances();

    expect(ruleFor('fleet.max_trip_hours', $this->w->c1))->toBe(8);
});

it('never reads a map cached before a deploy added a rule', function () {
    // Cached under the old catalog.
    expect(ruleFor('attendance.late_grace_minutes', $this->w->c1))->toBe(10);

    $registry = ModuleRegistry::fromManifests([[
        'key' => 'fleet',
        'name' => 'fleet::module.name',
        'version' => '0.1.0',
        'category' => 'business',
        'sectors' => ['*'],
        'plans' => ['*'],
        'rules' => [[
            'key' => 'fleet.max_trip_hours',
            'type' => 'integer',
            'schema' => ['minimum' => 1, 'maximum' => 24],
            'default' => 8,
            'label' => 'fleet::rules.max_trip_hours.label',
            'description' => 'fleet::rules.max_trip_hours.description',
            'overridable_levels' => ['platform', 'company'],
        ]],
    ]], app(PlanCatalog::class)->keys());

    // The new code starts with a fresh process: new catalog, new cache service, same cache store.
    app()->instance(RuleCatalog::class, RuleCatalog::fromModules($registry, app(RuleValueValidator::class)));
    app()->forgetInstance(RuleCache::class);
    app()->forgetScopedInstances();

    expect(ruleFor('fleet.max_trip_hours', $this->w->c1))->toBe(8);
});

it('prefers the value for the organization\'s country', function () {
    platformRule('payroll.overtime_multiplier', '2.0', country: 'BD');
    $india = createGroup($this->w->partnerA, 'India Group', ['country_code' => 'IN']);
    $indianCompany = createChild($india, OrganizationType::Company, 'Mumbai Co');

    expect(ruleFor('payroll.overtime_multiplier', $this->w->c1))->toBe('2.0')
        ->and(ruleFor('payroll.overtime_multiplier', $indianCompany))->toBe('1.5');
});

it('uses the nearest valid ancestor value when a stored value breaks a newer constraint', function () {
    orgRule($this->w->c1, 'attendance.late_grace_minutes', 20);
    orgRule($this->w->g1, 'attendance.late_grace_minutes', ['max' => 15], RuleMode::Constrain);

    $resolved = app(RuleResolver::class)->resolve('attendance.late_grace_minutes', app(RuleContextFactory::class)->forOrganization($this->w->c1));

    expect($resolved->value)->toBe(10)
        ->and($resolved->fellBack)->toBeTrue()
        ->and($resolved->sourceLevel)->toBeNull();
});

it('clamps to the limits when no ancestor value fits', function () {
    // Platform 60 is above the max, the default 10 is below the min.
    platformRule('attendance.late_grace_minutes', 60);
    orgRule($this->w->g1, 'attendance.late_grace_minutes', ['min' => 20, 'max' => 30], RuleMode::Constrain);

    expect(ruleFor('attendance.late_grace_minutes', $this->w->c1))->toBe(30);
});

it('only lets children narrow the limits', function () {
    orgRule($this->w->g1, 'attendance.late_grace_minutes', ['max' => 15], RuleMode::Constrain);

    expect(ruleError(fn () => orgRule($this->w->c1, 'attendance.late_grace_minutes', ['max' => 30], RuleMode::Constrain)))
        ->toBe('bounds_wider_than_parent');

    orgRule($this->w->c1, 'attendance.late_grace_minutes', ['max' => 10], RuleMode::Constrain);
    expect(ruleError(fn () => orgRule($this->w->b1, 'attendance.late_grace_minutes', 12)))->toBe('violates_constraint');
});

it('rejects invalid writes', function (Closure $write, string $code) {
    expect(ruleError(fn () => $write($this->w)))->toBe($code);
})->with([
    'wrong type' => [fn ($w) => orgRule($w->c1, 'attendance.late_grace_minutes', 'ten'), 'invalid_value'],
    'out of range' => [fn ($w) => orgRule($w->c1, 'attendance.late_grace_minutes', 999), 'invalid_value'],
    'unknown option' => [fn ($w) => orgRule($w->c1, 'payroll.pay_cycle', 'weekly'), 'invalid_value'],
    'level not allowed' => [fn ($w) => orgRule($w->c1, 'payroll.tax_slabs', []), 'level_not_allowed'],
    'module off' => [fn ($w) => orgRule($w->c1, 'ai_assistant.data_scope', 'company'), 'module_disabled'],
    'bad bounds' => [fn ($w) => orgRule($w->g1, 'attendance.geo_fence_required', ['max' => true], RuleMode::Constrain), 'invalid_bounds'],
    'unknown rule' => [fn ($w) => orgRule($w->c1, 'hrm.coffee_breaks', 3), 'unknown_rule'],
]);

it('rejects a country on a rule that is the same everywhere', function () {
    expect(ruleError(fn () => platformRule('payroll.pay_cycle', 'monthly', country: 'BD')))->toBe('not_country_specific');
});

it('restores an earlier version as a new change', function () {
    $first = orgRule($this->w->c1, 'attendance.late_grace_minutes', 15);
    orgRule($this->w->c1, 'attendance.late_grace_minutes', 25);

    $restored = ruleService()->rollback(app(RuleTargets::class)->organization($this->w->c1), 'attendance.late_grace_minutes', $first->version, 'Back to 15');

    expect($restored->version)->toBe(3)
        ->and(ruleFor('attendance.late_grace_minutes', $this->w->c1))->toBe(15)
        ->and(AuditLog::where('action', 'rule.changed')->latest('id')->first()->new_values['rolled_back_to_version'])->toBe(1);
});

it('keeps an append-only history of every change', function () {
    orgRule($this->w->c1, 'attendance.late_grace_minutes', 15);
    $this->travel(1)->seconds();
    orgRule($this->w->c1, 'attendance.late_grace_minutes', 25);

    $actions = RuleValueHistory::where('rule_key', 'attendance.late_grace_minutes')->orderBy('id')->pluck('action')->all();

    expect($actions)->toBe(['created', 'activated', 'created', 'closed', 'activated'])
        ->and(fn () => RuleValueHistory::first()->delete())->toThrow(LogicException::class);
});

it('resolves role and user level values below the organization', function () {
    toggles()->enable($this->w->g1, 'offline_mode', 'Test setup');
    $user = User::factory()->create();

    RuleValue::create([
        'rule_key' => 'offline_mode.offline_lease_hours', 'scope_type' => RuleScope::User, 'scope_id' => $user->id,
        'mode' => RuleMode::Set, 'value' => 72, 'version' => 1, 'status' => RuleValueStatus::Active, 'reason' => 'Field officer',
    ]);
    app(RuleCache::class)->flush(RuleScope::User, $user->id);

    $contexts = app(RuleContextFactory::class);
    expect(app(RuleResolver::class)->get('offline_mode.offline_lease_hours', $contexts->forOrganization($this->w->c1, user: $user)))->toBe(72)
        ->and(app(RuleResolver::class)->get('offline_mode.offline_lease_hours', $contexts->forOrganization($this->w->c1)))->toBe(24);
});

it('builds an offline snapshot with a version', function () {
    $snapshot = app(RuleResolver::class)->snapshot(
        ['offline_mode.offline_lease_hours', 'offline_mode.max_cached_records'],
        app(RuleContextFactory::class)->forOrganization($this->w->c1),
    );

    expect($snapshot['rules'])->toBe(['offline_mode.offline_lease_hours' => 24, 'offline_mode.max_cached_records' => 5000])
        ->and($snapshot['rule_version'])->toBeString();

    orgRule($this->w->g1, 'attendance.late_grace_minutes', 5);
    expect(app(RuleResolver::class)->snapshot(['offline_mode.max_cached_records'], app(RuleContextFactory::class)->forOrganization($this->w->c1))['rule_version'])
        ->not->toBe($snapshot['rule_version']);
});

it('drives the organization structure from rules', function () {
    ruleService()->set(
        app(RuleTargets::class)->partner($this->w->partnerA), 'tenancy.allowed_parents', RuleMode::Set,
        ['group' => ['root'], 'company' => ['group'], 'branch' => ['company'], 'department' => ['branch']],
        'Partner A requires branches', trusted: true,
    );

    expect(fn () => app(CreateOrganization::class)->handle(OrganizationType::Department, ['name' => ['en' => 'X']], parent: $this->w->c1))
        ->toThrow(HierarchyViolation::class);

    // Partner B keeps the platform structure.
    expect(createChild($this->w->c4, OrganizationType::Department, 'Direct department')->parent_id)->toBe($this->w->c4->id);
});
