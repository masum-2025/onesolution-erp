<?php

use App\Platform\Identity\Services\PersonalWorkspaces;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Partner;

/*
 * One person, one login, several memberships (Phase 5C acceptance): an
 * employee of Company A who is also a self-serve user never sees one
 * context's data from the other.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->house = Partner::factory()->house()->create();
    $this->person = createMember($this->w->c1, MembershipType::Staff, AccessScope::Own);
    $this->personal = app(PersonalWorkspaces::class)->create($this->person, $this->house, 'BD', 'en');
});

it('shows only the personal workspace inside the personal context', function () {
    $token = orgToken($this->person, $this->personal);

    $this->asToken($token)->getJson('/api/organizations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->personal->id);

    foreach ([$this->w->c1, $this->w->b1, $this->w->g1] as $companyUnit) {
        $this->asToken($token)->getJson("/api/organizations/{$companyUnit->id}")->assertNotFound();
        $this->asToken($token)->getJson("/api/organizations/{$companyUnit->id}/billing")->assertNotFound();
        $this->asToken($token)->getJson("/api/organizations/{$companyUnit->id}/audit-log")->assertNotFound();
    }
});

it('shows nothing of the personal workspace inside the company context', function () {
    $token = orgToken($this->person, $this->w->c1);

    $this->asToken($token)->getJson('/api/organizations')->assertOk()
        ->assertJsonMissing(['id' => $this->personal->id]);

    $this->asToken($token)->getJson("/api/organizations/{$this->personal->id}")->assertNotFound();
    $this->asToken($token)->getJson("/api/organizations/{$this->personal->id}/billing/self-serve")->assertNotFound();
    $this->asToken($token)->getJson("/api/organizations/{$this->personal->id}/upgrade")->assertNotFound();
});

it('lists both places to work, and nothing more, for the one login', function () {
    $this->asToken(orgToken($this->person, $this->personal))->getJson('/api/me')
        ->assertOk()
        ->assertJsonCount(2, 'data.contexts.organizations');
});
