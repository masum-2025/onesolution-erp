<?php

use App\Platform\Access\AccessResolver;
use App\Platform\SupportAccess\Enums\GrantStatus;
use App\Platform\SupportAccess\Models\SupportGrant;
use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Exceptions\OrganizationAccessDenied;
use Tests\Fixtures\TenantNote;

/*
 * Phase 11: the refusal paths of the classes that decide who may see and
 * change what (critical-path coverage), each proven on its own.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
});

function approvedGrant(object $w, $staff): SupportGrant
{
    return SupportGrant::create([
        'partner_id' => $w->partnerA->id,
        'organization_id' => $w->c1->id,
        'requested_by' => $staff->id,
        'reason' => 'Checking the payroll settings',
        'severity' => 'normal',
        'access' => 'read',
        'duration_minutes' => 60,
        'status' => GrantStatus::Approved,
        'starts_at' => now()->subMinute(),
        'expires_at' => now()->addHour(),
    ]);
}

it('refuses approved support access while the partner is suspended', function () {
    $staff = createPartnerStaff($this->w->partnerA, PartnerUserRole::Support);
    $grant = approvedGrant($this->w, $staff);

    // Usable while all is well.
    expect(app(ContextResolver::class)->enterSupport($staff, $grant->id)->isSupport())->toBeTrue();

    $this->w->partnerA->forceFill(['status' => 'suspended'])->save();
    app(CurrentContext::class)->clear();

    expect(fn () => app(ContextResolver::class)->enterSupport($staff, $grant->id))
        ->toThrow(OrganizationAccessDenied::class, 'no_support_access');
});

it('refuses the console of a suspended partner, even to its own staff', function () {
    $owner = createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner);
    $this->w->partnerA->forceFill(['status' => 'suspended'])->save();

    expect(fn () => app(ContextResolver::class)->enterPartner($owner, $this->w->partnerA->id))
        ->toThrow(OrganizationAccessDenied::class, 'partner_inactive');
});

it('knows the company, group and region of the context', function () {
    $context = actInOrganization(createMember($this->w->b1), $this->w->b1);

    expect($context->company()->id)->toBe($this->w->c1->id)
        ->and($context->group()->id)->toBe($this->w->g1->id)
        ->and($context->region())->toBe(config('tenancy.defaults.region'));
});

it('never writes without a context, or while the context is restricted', function () {
    $context = app(CurrentContext::class);
    $context->clear();
    expect($context->canWriteTo($this->w->c1->id))->toBeFalse();

    actInOrganization(createMember($this->w->c1), $this->w->c1);
    expect($context->canWriteTo($this->w->c1->id))->toBeTrue()
        ->and($context->canWriteTo(null))->toBeFalse();

    $context->restrict(CurrentContext::MODE_READ_ONLY, 'support');
    expect($context->canWriteTo($this->w->c1->id))->toBeFalse();
});

it('links a business record to its organization', function () {
    $note = TenantNote::create(['title' => 'x', 'organization_id' => $this->w->c1->id]);

    expect($note->organization->id)->toBe($this->w->c1->id);
});

it('defines every tenant database from the configuration at boot', function () {
    config(['tenant_databases.databases' => ['eu1' => ['database' => 'erp_eu1', 'host' => '10.0.0.5']]]);

    TenantDatabases::registerConfigured();

    expect(config('database.connections.tenant_eu1'))->toMatchArray(['database' => 'erp_eu1', 'host' => '10.0.0.5', 'driver' => config('database.connections.'.config('database.default').'.driver')]);
});

it('grants nothing without a context, to portal members, or for unknown permissions', function () {
    app(CurrentContext::class)->clear();
    $access = app(AccessResolver::class);
    expect($access->held())->toBe([])
        ->and($access->manages($this->w->c1))->toBeFalse();

    actInOrganization(createMember($this->w->c1, MembershipType::Portal), $this->w->c1);
    $access->forget();
    expect($access->held())->toBe([])
        ->and($access->moduleEnabled('nothing.like.this', $this->w->c1))->toBeFalse();
});
