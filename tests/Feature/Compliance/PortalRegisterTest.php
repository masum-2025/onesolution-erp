<?php

use App\Models\User;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Portal\PortalSubjects;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;

/*
 * A factory's audit register in the client's portal: a buyer or an auditor
 * the client invites sees only the factories linked to them (a company with
 * its branches), read only, without notes, renewals or money.
 */

beforeEach(function () {
    // Thursday 29 October 2026 in Dhaka.
    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'));
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g3] as $group) {
        setPlan($group, 'business');
        foreach (['compliance', 'client_portal'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $person = function (Organization $at, string $name) {
        $user = createMember($at, MembershipType::Staff, AccessScope::Descendants);
        giveRoles($user, $at, makeRole($at, ['compliance.view', 'compliance.manage', 'compliance.set_expiry'], $name));

        return orgToken($user, $at);
    };
    $gm = $person($this->w->g1, 'GM');
    $this->hrac = ['c1' => $person($this->w->c1, 'HRAC C1'), 'c2' => $person($this->w->c2, 'HRAC C2')];
    $this->api = fn (string $path, ?Organization $at = null) => '/api/organizations/'.($at ?? $this->w->g1)->id."/compliance/{$path}";
    $this->asToken($gm)->postJson(($this->api)('lists/services/defaults'))->assertOk();
    $services = collect($this->asToken($gm)->getJson(($this->api)('lists/services'))->json('data'))->pluck('id', 'code');
    $line = fn (string $company, Organization $factory, string $code, array $data) => $this->asToken($this->hrac[$company])
        ->postJson(($this->api)('audits', $this->w->{$company}), ['unit_id' => $factory->id, 'service_id' => $services[$code], ...$data])->assertCreated();
    $line('c1', $this->w->c1, 'BSCI', ['last_audit_on' => '2025-11-20', 'expires_on' => '2026-11-20', 'result' => 'B', 'certificate_no' => 'BSCI-C1-77', 'notes' => 'Buyer must not see this']);
    $line('c1', $this->w->b1, 'WRAP', ['last_audit_on' => '2026-06-01', 'expires_on' => '2027-06-01', 'result' => 'Gold', 'certificate_no' => 'WRAP-B1-9']);
    $line('c1', $this->w->b1, 'SMETA', ['last_audit_on' => '2025-09-01', 'expires_on' => '2026-09-01']);
    $line('c2', $this->w->c2, 'BSCI', ['last_audit_on' => '2026-01-10', 'expires_on' => '2027-01-10', 'certificate_no' => 'BSCI-C2-1']);

    $this->withHeader('Origin', config('app.url'));
});

/** A portal member of the organization, linked to the given factories (as the client's invitation would). */
function compliancePortalMember(Organization $at, array $factories): string
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    app(AddMember::class)->handle($at, $user, MembershipType::Portal, AccessScope::Own);
    $membership = OrganizationMembership::query()->where('user_id', $user->id)->where('organization_id', $at->id)->sole();
    foreach ($factories as $factory) {
        (new PortalLink)->forceFill([
            'organization_id' => $at->id, 'membership_id' => $membership->id, 'user_id' => $user->id,
            'subject_type' => 'compliance.factory', 'subject_id' => $factory->id, 'relation' => 'viewer', 'status' => PortalLink::ACTIVE, 'linked_via' => 'invitation',
        ])->save();
    }

    return orgToken($user, $at);
}

it('shows a buyer the register of the factory linked to them, and nothing more', function () {
    $buyer = compliancePortalMember($this->w->c1, [$this->w->b1]);

    $data = $this->asToken($buyer)->getJson('/api/portal/compliance')->assertOk()->json('data');
    expect($data['factories'])->toHaveCount(1)
        ->and($data['factories'][0]['id'])->toBe($this->w->b1->id)
        ->and($data['factories'][0]['units'])->toHaveCount(1);
    $unit = $data['factories'][0]['units'][0];
    // Most urgent first: the expired SMETA, then WRAP.
    expect(array_column(array_column($unit['lines'], 'service'), 'code'))->toBe(['SMETA', 'WRAP'])
        ->and($unit['summary'])->toMatchArray(['valid' => 1, 'expired' => 1, 'expiring' => 0])
        ->and($unit['lines'][1])->toMatchArray(['expires_on' => '2027-06-01', 'state' => 'valid', 'result' => 'Gold', 'certificate_no' => 'WRAP-B1-9'])
        ->and($unit['lines'][0])->toMatchArray(['state' => 'expired', 'days_left' => -58]);
    expect(json_encode($data))->not->toContain('BSCI-C1-77')->not->toContain('notes')->not->toContain('version')->not->toContain('renewal');

    // C1's own line and another company's factory, asked for by id: the same not found.
    $this->asToken($buyer)->getJson('/api/portal/compliance?factory='.$this->w->c1->id)->assertNotFound()->assertJsonPath('code', 'portal_factory_not_found');
    $this->asToken($buyer)->getJson('/api/portal/compliance?factory='.$this->w->c2->id)->assertNotFound();
    // The staff register stays closed to portal members; staff get nothing from the portal API.
    $this->asToken($buyer)->getJson(($this->api)('register', $this->w->c1))->assertForbidden();
    expect($this->asToken($this->hrac['c1'])->getJson('/api/portal/compliance')->json('data.factories'))->toBe([]);
});

it('shows a linked company with its branches, each as its own factory', function () {
    $auditor = compliancePortalMember($this->w->g1, [$this->w->c1]);

    $factories = $this->asToken($auditor)->getJson('/api/portal/compliance?factory='.$this->w->c1->id)->assertOk()->json('data.factories');
    expect(array_column($factories[0]['units'], 'id'))->toBe([$this->w->c1->id, $this->w->b1->id])
        ->and($factories[0]['units'][0]['lines'][0])->toMatchArray(['state' => 'expiring', 'days_left' => 22, 'certificate_no' => 'BSCI-C1-77'])
        ->and(json_encode($factories))->not->toContain('BSCI-C2-1');
});

it('hides certificate numbers when the client\'s rule says so', function () {
    orgRule($this->w->c1, 'compliance.portal_shows_certificate_no', false);
    $buyer = compliancePortalMember($this->w->c1, [$this->w->b1]);

    $lines = $this->asToken($buyer)->getJson('/api/portal/compliance')->assertOk()->json('data.factories.0.units.0.lines');
    expect(array_column($lines, 'certificate_no'))->toBe([null, null]);
});

it('opens the register from the portal home, and lets the client find factories to share', function () {
    $buyer = compliancePortalMember($this->w->c1, [$this->w->b1]);
    $home = $this->asToken($buyer)->getJson('/api/portal')->assertOk()->json('data.records');
    expect($home)->toHaveCount(1)
        ->and($home[0])->toMatchArray(['name' => 'B1', 'page' => "/portal/compliance/{$this->w->b1->id}"]);

    $kind = app(PortalSubjects::class)->provider('compliance.factory');
    expect($kind->relations())->toBe(['viewer'])
        ->and(array_map(fn ($subject) => $subject->id, $kind->search($this->w->c1, '')))->toBe([$this->w->c1->id, $this->w->b1->id])
        ->and($kind->find($this->w->c1, $this->w->b1->id)?->name)->toBe('B1')
        // Outside the organization (another company of the group, another partner's): not found.
        ->and($kind->find($this->w->c1, $this->w->c2->id))->toBeNull()
        ->and($kind->find($this->w->c1, $this->w->c4->id))->toBeNull()
        ->and($kind->find($this->w->c1, $this->w->d1->id))->toBeNull();
});

it('refuses the portal register while Compliance or the portal is off', function () {
    $buyer = compliancePortalMember($this->w->c1, [$this->w->b1]);
    toggles()->disable($this->w->c1, 'compliance', 'Test');
    $this->asToken($buyer)->getJson('/api/portal/compliance')->assertForbidden();

    toggles()->enable($this->w->c1, 'compliance', 'Test');
    toggles()->disable($this->w->c1, 'client_portal', 'Test');
    $this->asToken($buyer)->getJson('/api/portal/compliance')->assertForbidden();
});

it('never shows another tenant\'s factory, even through a link made by hand', function () {
    // A link in G3 (another partner) naming C1's factory: G3's portal finds no such factory.
    $stranger = compliancePortalMember($this->w->c4, [$this->w->b1]);
    expect($this->asToken($stranger)->getJson('/api/portal/compliance')->assertOk()->json('data.factories'))->toBe([]);
    $this->asToken($stranger)->getJson('/api/portal/compliance?factory='.$this->w->b1->id)->assertNotFound();

    // A portal member of C2 linked to B1 (not below C2): nothing either.
    $neighbour = compliancePortalMember($this->w->c2, [$this->w->b1]);
    expect($this->asToken($neighbour)->getJson('/api/portal/compliance')->json('data.factories'))->toBe([]);
});
