<?php

use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\PartnerUser;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->staffA = createPartnerStaff($this->w->partnerA);
    $this->tokenA = partnerToken($this->staffA, $this->w->partnerA);
});

it('lists only its own clients in the partner console', function () {
    $ids = $this->asToken($this->tokenA)->getJson('/api/partner/organizations')
        ->assertOk()
        ->json('data.*.id');

    $expected = Organization::where('partner_id', $this->w->partnerA->id)->pluck('id')->all();

    expect($ids)->toEqualCanonicalizing($expected)
        ->and($ids)->not->toContain($this->w->g3->id, $this->w->c4->id);
});

it('cannot read or guess another partner\'s organizations', function () {
    $this->asToken($this->tokenA)->getJson("/api/partner/organizations/{$this->w->c4->id}")
        ->assertNotFound()
        ->assertJsonPath('code', 'organization_not_found');
    $this->asToken($this->tokenA)->getJson('/api/partner/organizations/'.Str::ulid())->assertNotFound();
});

it('shows only metadata of its own clients', function () {
    $this->asToken($this->tokenA)->getJson("/api/partner/organizations/{$this->w->c1->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.settings')
        ->assertJsonMissingPath('data.path');
});

it('cannot enter the console of another partner', function () {
    $this->asToken($this->tokenA)->postJson('/api/auth/context', ['partner_id' => $this->w->partnerB->id])
        ->assertForbidden()
        ->assertJsonPath('code', 'not_partner_member');
});

it('cannot read client business data without support access', function () {
    // A partner token carries no organization, so client routes refuse it.
    $this->asToken($this->tokenA)->getJson('/api/organizations')
        ->assertForbidden()
        ->assertJsonPath('code', 'no_context');

    // And partner staff are not members of client organizations.
    $this->asToken($this->tokenA)->postJson('/api/auth/context', ['organization_id' => $this->w->c1->id])
        ->assertForbidden()
        ->assertJsonPath('code', 'not_member');
});

it('does not let a client token into the partner console', function () {
    $clientOwner = createMember($this->w->c1, MembershipType::Owner);

    $this->asToken(orgToken($clientOwner, $this->w->c1))->getJson('/api/partner/organizations')
        ->assertForbidden()
        ->assertJsonPath('code', 'not_partner_member');
});

it('blocks suspended partner staff on the next request', function () {
    PartnerUser::where('user_id', $this->staffA->id)->update(['status' => MembershipStatus::Suspended]);

    $this->asToken($this->tokenA)->getJson('/api/partner/organizations')->assertForbidden();
});
