<?php

use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Modules\Crm\Models\Contact;

/*
 * A project's client is any CRM contact, a person or an organization:
 * chosen from the company's contacts (clients first, tagged "client" once
 * chosen) or added from the project form with its kind and details, by
 * CRM's own rules. Without CRM it is only a name.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 20:00:00', 'UTC'));
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g3] as $group) {
        setPlan($group, 'business');
        toggles()->enable($group, 'projects', 'Test setup');
        toggles()->enable($group, 'crm', 'Test setup');
    }
    $person = function (Organization $at, array $permissions, string $name) {
        $user = createMember($at, MembershipType::Staff, AccessScope::Descendants);
        giveRoles($user, $at, makeRole($at, $permissions, $name));

        return $user;
    };
    $this->manager = orgToken($person($this->w->c1, ['projects.view', 'projects.manage', 'crm.view', 'crm.edit'], 'Manager'), $this->w->c1);
    $this->noCrm = orgToken($person($this->w->c1, ['projects.view', 'projects.manage'], 'Projects only'), $this->w->c1);
    $this->api = fn (string $path = '') => "/api/organizations/{$this->w->c1->id}/projects".($path === '' ? '' : "/{$path}");
    $this->crm = fn (array $data) => $this->asToken($this->manager)->postJson("/api/organizations/{$this->w->c1->id}/crm/contacts", $data)->assertCreated()->json('data');
});

it('offers people and organizations from CRM, clients first, and tags the one chosen', function () {
    $rahim = ($this->crm)(['kind' => 'person', 'name' => 'Rahim Uddin', 'company_name' => 'Rider Group', 'phone' => '01711-000001']);
    ($this->crm)(['kind' => 'organization', 'name' => 'Rider Group', 'phone' => '01711-000002', 'tags' => ['client']]);
    ($this->crm)(['kind' => 'organization', 'name' => 'Rizwan Traders']);

    $all = $this->asToken($this->manager)->getJson(($this->api)('clients').'?q=Ri')->assertOk()->json('data');
    // The tagged client first, then A to Z; a person found by the company they work for too.
    expect(collect($all)->pluck('name')->all())->toBe(['Rider Group', 'Rahim Uddin', 'Rizwan Traders'])
        ->and($all[0])->toMatchArray(['kind' => 'organization', 'tagged' => true])
        ->and($all[1])->toMatchArray(['kind' => 'person', 'company_name' => 'Rider Group', 'tagged' => false]);
    $this->asToken($this->manager)->getJson(($this->api)('clients').'?kind=person')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Rahim Uddin');
    $this->asToken($this->manager)->getJson(($this->api)('clients').'?q=0002')->assertOk()->assertJsonPath('data.0.name', 'Rider Group');
    $this->asToken($this->manager)->getJson(($this->api)('clients').'?kind=shop')->assertUnprocessable();

    // A person as the client: kept by id and name, and now tagged a client in CRM.
    $project = $this->asToken($this->manager)->postJson(($this->api)(), ['name' => 'Wage audit', 'client_contact_id' => $rahim['id'], 'starts_on' => '2026-11-01'])
        ->assertCreated()->json('data');
    expect($project['client'])->toMatchArray(['contact_id' => $rahim['id'], 'name' => 'Rahim Uddin', 'kind' => 'person'])
        ->and(Contact::query()->withoutGlobalScope(OrganizationScope::class)->findOrFail($rahim['id'])->tags)->toContain('client');
});

it('adds a new client to CRM from the project form, with its kind and details', function () {
    $this->asToken($this->manager)->getJson(($this->api)('options'))->assertOk()->assertJsonPath('data.can.add_client', true);
    $added = $this->asToken($this->manager)->postJson(($this->api)('clients'), [
        'kind' => 'person', 'name' => 'Karim Mia', 'company_name' => 'Karim Knit', 'phone' => '01811-222333', 'email' => 'Karim@Example.com',
        'address' => ['line1' => 'House 4, Road 2', 'city' => 'Gazipur', 'district' => 'Gazipur'],
    ])->assertCreated()->json('data');
    expect($added)->toMatchArray(['kind' => 'person', 'name' => 'Karim Mia', 'company_name' => 'Karim Knit', 'phone' => '+8801811222333', 'email' => 'karim@example.com', 'tagged' => true]);
    $contact = Contact::query()->withoutGlobalScope(OrganizationScope::class)->findOrFail($added['id']);
    expect($contact->address['city'])->toBe('Gazipur')->and($contact->source)->toBe('projects')->and($contact->tags)->toBe(['client']);

    // CRM's own rules: one contact per phone; a kind it knows; nothing unknown.
    $this->asToken($this->manager)->postJson(($this->api)('clients'), ['kind' => 'organization', 'name' => 'Same phone', 'phone' => '+880 1811 222333'])
        ->assertUnprocessable()->assertJsonValidationErrors('phone');
    $this->asToken($this->manager)->postJson(($this->api)('clients'), ['kind' => 'shop', 'name' => 'X Y'])->assertUnprocessable();
    $this->asToken($this->manager)->postJson(($this->api)('clients'), ['kind' => 'person', 'name' => 'X Y', 'owner_id' => 'x'])->assertUnprocessable();

    // Then chosen for a project at once.
    $this->asToken($this->manager)->postJson(($this->api)(), ['name' => 'Karim Knit WRAP', 'client_contact_id' => $added['id'], 'starts_on' => '2026-11-01'])
        ->assertCreated()->assertJsonPath('data.client.name', 'Karim Mia');
});

it('needs CRM\'s own permission to add clients, and works with a name without CRM', function () {
    $this->asToken($this->noCrm)->getJson(($this->api)('options'))->assertOk()->assertJsonPath('data.can.add_client', false);
    $this->asToken($this->noCrm)->postJson(($this->api)('clients'), ['kind' => 'person', 'name' => 'Karim Mia'])->assertForbidden();
    // Another tenant's contact is not a client here.
    $other = $this->asToken(orgToken(createMember($this->w->c4), $this->w->c4))->postJson("/api/organizations/{$this->w->c4->id}/crm/contacts", ['kind' => 'person', 'name' => 'Stranger'])
        ->assertCreated()->json('data.id');
    $this->asToken($this->manager)->postJson(($this->api)(), ['name' => 'Bad client', 'client_contact_id' => $other, 'starts_on' => '2026-11-01'])
        ->assertUnprocessable()->assertJsonPath('code', 'client_unknown');

    toggles()->disable($this->w->g1, 'crm', 'Test');
    $this->asToken($this->manager)->getJson(($this->api)('options'))->assertJsonPath('data.uses_crm', false)->assertJsonPath('data.can.add_client', false);
    $this->asToken($this->manager)->getJson(($this->api)('clients'))->assertOk()->assertJsonCount(0, 'data');
    $this->asToken($this->manager)->postJson(($this->api)(), ['name' => 'Name only', 'client_name' => 'Walk-in buyer', 'starts_on' => '2026-11-01'])
        ->assertCreated()->assertJsonPath('data.client.contact_id', null)->assertJsonPath('data.client.name', 'Walk-in buyer');
});
