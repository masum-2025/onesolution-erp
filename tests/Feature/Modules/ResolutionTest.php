<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Modules\Enums\ResolutionReason;
use App\Platform\Modules\Exceptions\ModuleException;
use App\Platform\Modules\Models\OrganizationModule;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Modules\Services\ModuleConsentService;

beforeEach(function () {
    $this->w = tenancyWorld();
});

it('keeps every module off until someone turns it on', function () {
    $resolved = app(ModuleResolver::class)->resolveAll($this->w->c1);

    expect(collect($resolved)->filter->enabled)->toBeEmpty()
        ->and($resolved['hrm']->reason)->toBe(ResolutionReason::NotEnabled)
        ->and($resolved['hrm']->source)->toBe('default');
});

it('auto-enables dependencies when enabling payroll', function () {
    $autoEnabled = toggles()->enable($this->w->c1, 'payroll', 'Start running payroll');

    expect($autoEnabled)->toEqualCanonicalizing(['hrm', 'attendance'])
        ->and(resolvedModule('payroll', $this->w->c1)->enabled)->toBeTrue()
        ->and(resolvedModule('hrm', $this->w->c1)->enabled)->toBeTrue()
        ->and(resolvedModule('attendance', $this->w->c1)->enabled)->toBeTrue();
});

it('passes a company decision down to its branches', function () {
    toggles()->enable($this->w->c1, 'crm', 'Sales team starts');

    $branch = resolvedModule('crm', $this->w->b1);

    expect($branch->enabled)->toBeTrue()
        ->and($branch->source)->toBe('inherited')
        ->and($branch->sourceOrganizationId)->toBe($this->w->c1->id)
        ->and(resolvedModule('crm', $this->w->c2)->enabled)->toBeFalse();
});

it('asks for confirmation before disabling modules that others depend on', function () {
    toggles()->enable($this->w->c1, 'payroll', 'Start running payroll');

    try {
        toggles()->disable($this->w->c1, 'hrm', 'Stop HR');
        $this->fail('Expected a confirmation request.');
    } catch (ModuleException $e) {
        expect($e->status())->toBe(409)->and($e->errorCode())->toBe('dependents_need_confirmation');
    }

    expect(resolvedModule('hrm', $this->w->c1)->enabled)->toBeTrue();
});

it('disables dependents too once confirmed', function () {
    toggles()->enable($this->w->c1, 'payroll', 'Start running payroll');

    $alsoDisabled = toggles()->disable($this->w->c1, 'hrm', 'Stop HR', confirm: true);

    expect($alsoDisabled)->toEqualCanonicalizing(['attendance', 'payroll'])
        ->and(resolvedModule('hrm', $this->w->c1)->enabled)->toBeFalse()
        ->and(resolvedModule('attendance', $this->w->c1)->enabled)->toBeFalse()
        ->and(resolvedModule('payroll', $this->w->c1)->enabled)->toBeFalse();
});

it('lets a group lock a module off for every company and branch below it', function () {
    toggles()->disable($this->w->g1, 'crm', 'Group policy: no CRM', lock: true);

    foreach ([$this->w->c1, $this->w->b1] as $organization) {
        expect(fn () => toggles()->enable($organization, 'crm', 'Trying anyway'))
            ->toThrow(ModuleException::class);
    }

    $resolved = resolvedModule('crm', $this->w->b1);
    expect($resolved->enabled)->toBeFalse()
        ->and($resolved->reason)->toBe(ResolutionReason::LockedDisabled)
        ->and($resolved->lockedByOrganizationId)->toBe($this->w->g1->id);
});

it('ignores older child overrides once a parent locks', function () {
    toggles()->enable($this->w->c1, 'crm', 'Company wants CRM');
    toggles()->disable($this->w->g1, 'crm', 'Group policy: no CRM', lock: true);

    expect(resolvedModule('crm', $this->w->c1)->enabled)->toBeFalse();
});

it('lets a group lock a module on so children cannot turn it off', function () {
    toggles()->enable($this->w->g1, 'hrm', 'HR everywhere', lock: true);

    expect(resolvedModule('hrm', $this->w->b1)->enabled)->toBeTrue()
        ->and(fn () => toggles()->disable($this->w->c1, 'hrm', 'Company opts out'))
        ->toThrow(ModuleException::class);
});

it('lets a branch override its company unless locked, and inherit again', function () {
    toggles()->enable($this->w->c1, 'crm', 'Company wants CRM');
    toggles()->disable($this->w->b1, 'crm', 'Branch does not need it');

    expect(resolvedModule('crm', $this->w->b1)->enabled)->toBeFalse()
        ->and(resolvedModule('crm', $this->w->c1)->enabled)->toBeTrue();

    toggles()->inherit($this->w->b1, 'crm', 'Follow the company again');

    expect(resolvedModule('crm', $this->w->b1)->enabled)->toBeTrue();
});

it('disables a module whose dependency a parent locked off', function () {
    toggles()->enable($this->w->c1, 'payroll', 'Start running payroll');
    // Nothing depends on attendance at group level, so no confirmation is needed there.
    toggles()->disable($this->w->g1, 'attendance', 'Group-wide time clock replacement', lock: true);

    $payroll = resolvedModule('payroll', $this->w->d1);

    expect($payroll->enabled)->toBeFalse()
        ->and($payroll->reason)->toBe(ResolutionReason::DependencyDisabled)
        ->and($payroll->blockedBy)->toBe(['attendance']);
});

it('offers modules only in the plans they are sold in', function () {
    expect(resolvedModule('custom_reports', $this->w->c1)->reason)->toBe(ResolutionReason::NotInPlan)
        ->and(fn () => toggles()->enable($this->w->c1, 'custom_reports', 'Want reports'))
        ->toThrow(ModuleException::class);

    setPlan($this->w->g1, 'business');
    toggles()->enable($this->w->c1, 'custom_reports', 'Upgraded plan');

    expect(resolvedModule('custom_reports', $this->w->b1)->enabled)->toBeTrue();
});

it('offers modules only to the sectors they are built for', function () {
    // C1 is a school; factory_erp is for factories only.
    toggles()->enable($this->w->g1, 'factory_erp', 'Group-wide factory rollout');

    expect(resolvedModule('factory_erp', $this->w->g1)->enabled)->toBeTrue()
        ->and(resolvedModule('factory_erp', $this->w->c1)->reason)->toBe(ResolutionReason::SectorNotAllowed)
        ->and(fn () => toggles()->enable($this->w->c1, 'factory_erp', 'School tries'))
        ->toThrow(ModuleException::class);
});

it('requires admin consent for AI modules, and revoking it turns them off', function () {
    setPlan($this->w->g1, 'business');
    $admin = User::factory()->create();

    expect(fn () => toggles()->enable($this->w->c1, 'ai_assistant', 'Try AI'))->toThrow(ModuleException::class);

    app(ModuleConsentService::class)->grant($this->w->c1, 'ai_assistant', 'v1', 'Reviewed the DPA', $admin);
    toggles()->enable($this->w->c1, 'ai_assistant', 'Try AI');
    expect(resolvedModule('ai_assistant', $this->w->b1)->enabled)->toBeTrue();

    app(ModuleConsentService::class)->revoke($this->w->c1, 'ai_assistant', 'Legal asked to pause', $admin);
    expect(resolvedModule('ai_assistant', $this->w->b1)->reason)->toBe(ResolutionReason::ConsentMissing);
});

it('audits every toggle with its reason', function () {
    toggles()->enable($this->w->c1, 'payroll', 'Start running payroll');

    $entries = AuditLog::where('action', 'module.enabled')->get();

    expect($entries)->toHaveCount(3)
        ->and($entries->pluck('reason')->unique()->all())->toBe(['Start running payroll'])
        ->and($entries->pluck('new_values.module')->all())->toEqualCanonicalizing(['hrm', 'attendance', 'payroll']);
});

it('invalidates cached results when a parent changes', function () {
    $resolver = app(ModuleResolver::class);
    expect($resolver->isEnabled('crm', $this->w->d1))->toBeFalse();

    toggles()->enable($this->w->g1, 'crm', 'Group rollout');
    expect($resolver->isEnabled('crm', $this->w->d1))->toBeTrue();

    setPlan($this->w->g1, 'starter');
    toggles()->disable($this->w->g1, 'crm', 'Rollback', lock: true);
    expect($resolver->isEnabled('crm', $this->w->d1))->toBeFalse();
});

it('never deletes settings or data when a module is turned off', function () {
    toggles()->enable($this->w->c1, 'hrm', 'Start HR');
    toggles()->disable($this->w->c1, 'hrm', 'Pause HR');

    expect(OrganizationModule::where('organization_id', $this->w->c1->id)->where('module_key', 'hrm')->exists())->toBeTrue();
});
