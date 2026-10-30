<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\DocumentLine;
use Modules\Projects\Models\ProjectLink;

/*
 * PRJ-2: a project's money is kept by the modules that keep money. Quotes
 * are CRM's (accepting a quotation may make Accounting's invoice); invoices
 * are Accounting's, on the income account mapped to projects.services.
 * Projects keeps only the links and reads numbers and amounts each time.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 04:00:00', 'UTC'));
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g3] as $group) {
        setPlan($group, 'business');
        foreach (['projects', 'crm', 'accounting'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $person = function (Organization $at, array $permissions, string $name) {
        $user = createMember($at, MembershipType::Staff, AccessScope::Descendants);
        giveRoles($user, $at, makeRole($at, $permissions, $name));

        return $user;
    };
    $this->managerUser = $person($this->w->c1, ['projects.view', 'projects.manage', 'projects.bill', 'crm.view', 'crm.edit', 'accounting.view', 'accounting.sell', 'accounting.manage'], 'Billing manager');
    $this->manager = orgToken($this->managerUser, $this->w->c1);
    $this->noBill = orgToken($person($this->w->c1, ['projects.view', 'projects.manage', 'crm.view', 'crm.edit'], 'Runs projects'), $this->w->c1);
    $this->noCrm = orgToken($person($this->w->c1, ['projects.view', 'projects.bill', 'accounting.view', 'accounting.sell'], 'Bills only'), $this->w->c1);
    setUpBooks($this, $this->manager, $this->w->c1)->assertCreated();
    $this->api = fn (string $path = '', ?Organization $at = null) => '/api/organizations/'.($at ?? $this->w->c1)->id.'/projects'.($path === '' ? '' : "/{$path}");
    $this->project = $this->asToken($this->manager)->postJson(($this->api)(), ['name' => 'Rider Group BSCI', 'client_name' => 'Rider Group', 'starts_on' => '2026-11-05'])
        ->assertCreated()->json('data');
    $this->lines = [
        ['description' => 'Audit coordination', 'quantity_milli' => 1000, 'unit_price_minor' => 4500000],
        ['description' => 'Follow-up visit', 'quantity_milli' => 2000, 'unit_price_minor' => 500000],
    ];
});

it('quotes the client in CRM; accepting it there makes the invoice, shown on the project', function () {
    $money = $this->asToken($this->manager)->getJson(($this->api)("{$this->project['id']}/money"))->assertOk()->json('data');
    expect($money['can'])->toMatchArray(['quote' => true, 'invoice' => true, 'client_to_crm' => false])->and($money['quotes'])->toBe([]);

    $quote = $this->asToken($this->manager)->postJson(($this->api)("{$this->project['id']}/quotes"), ['kind' => 'quotation', 'lines' => $this->lines])
        ->assertCreated()->json('data');
    expect($quote)->toMatchArray(['kind' => 'quotation', 'status' => 'draft', 'total_minor' => 5500000, 'number' => 'QT-2026-00001']);

    // Accepted in CRM, with its invoice: the project shows both, read from CRM and Accounting.
    $this->asToken($this->manager)->postJson("/api/organizations/{$this->w->c1->id}/crm/quotes/{$quote['id']}/accept", ['base_version' => 1, 'invoice' => true])->assertOk();
    $money = $this->asToken($this->manager)->getJson(($this->api)("{$this->project['id']}/money"))->assertOk()->json('data');
    expect($money['quotes'][0]['status'])->toBe('accepted')
        ->and($money['invoices'])->toHaveCount(1)
        ->and($money['invoices'][0])->toMatchArray(['status' => 'draft', 'total_minor' => 5500000])
        ->and($money['totals'])->toMatchArray(['quoted' => 5500000, 'invoiced' => 0, 'outstanding' => 0, 'currency_code' => 'BDT']);
    expect(AuditLog::query()->where('action', 'projects.quote_linked')->exists())->toBeTrue();
});

it('invoices the client straight from the project, on the project services income account', function () {
    $invoice = $this->asToken($this->manager)->postJson(($this->api)("{$this->project['id']}/invoices"), ['issue_date' => '2026-11-05', 'lines' => [
        ['description' => 'First instalment', 'quantity_milli' => 1000, 'unit_price_minor' => 2000000],
    ]])->assertCreated()->json('data');
    expect($invoice)->toMatchArray(['status' => 'draft', 'total_minor' => 2000000]);

    $document = Document::query()->withoutGlobalScope(OrganizationScope::class)->findOrFail($invoice['id']);
    expect($document->reference)->toBe($this->project['code'])
        ->and(DocumentLine::query()->withoutGlobalScope(OrganizationScope::class)->where('document_id', $document->id)->value('account_id'))->toBe(accountId($this->w->c1, '4100'));

    // A second instalment: one project, as many invoices as needed.
    $this->asToken($this->manager)->postJson(($this->api)("{$this->project['id']}/invoices"), ['issue_date' => '2026-11-06', 'lines' => [
        ['description' => 'Second instalment', 'quantity_milli' => 1000, 'unit_price_minor' => 3000000],
    ]])->assertCreated();
    $this->asToken($this->manager)->getJson(($this->api)("{$this->project['id']}/money"))->assertOk()->assertJsonCount(2, 'data.invoices');
    expect(ProjectLink::query()->withoutGlobalScope(OrganizationScope::class)->where('kind', 'invoice')->count())->toBe(2);

    // Lines are checked like everywhere: no unknown fields, no negative prices.
    $this->asToken($this->manager)->postJson(($this->api)("{$this->project['id']}/invoices"), ['issue_date' => '2026-11-05', 'lines' => [
        ['description' => 'X', 'quantity_milli' => 1000, 'unit_price_minor' => -5],
    ]])->assertUnprocessable();
    $this->asToken($this->manager)->postJson(($this->api)("{$this->project['id']}/invoices"), ['issue_date' => '2026-11-05', 'kind' => 'quotation', 'lines' => $this->lines])->assertUnprocessable();
});

it('turns a client kept only by name into a CRM client before quoting', function () {
    toggles()->disable($this->w->g1, 'crm', 'Test');
    $named = $this->asToken($this->manager)->postJson(($this->api)(), ['name' => 'Walk-in job', 'client_name' => 'Karim Traders', 'starts_on' => '2026-11-05'])
        ->assertCreated()->assertJsonPath('data.client.contact_id', null)->json('data');
    toggles()->enable($this->w->g1, 'crm', 'Test');

    $money = $this->asToken($this->manager)->getJson(($this->api)("{$named['id']}/money"))->assertOk()->json('data');
    expect($money['state'])->toMatchArray(['crm' => true, 'client_in_crm' => false])->and($money['can'])->toMatchArray(['quote' => false, 'client_to_crm' => true]);
    $this->asToken($this->manager)->postJson(($this->api)("{$named['id']}/quotes"), ['kind' => 'estimate', 'lines' => $this->lines])
        ->assertUnprocessable()->assertJsonPath('code', 'client_not_in_crm');

    $this->asToken($this->manager)->postJson(($this->api)("{$named['id']}/client/crm"), ['base_version' => 1])->assertOk()->assertJsonPath('data.client.name', 'Karim Traders');
    $this->asToken($this->manager)->postJson(($this->api)("{$named['id']}/quotes"), ['kind' => 'estimate', 'lines' => $this->lines])
        ->assertCreated()->assertJsonPath('data.kind', 'estimate');
});

it('keeps billing to people allowed in all the modules involved, and works without them', function () {
    // Projects without projects.bill; or without CRM's own permission.
    $this->asToken($this->noBill)->postJson(($this->api)("{$this->project['id']}/quotes"), ['kind' => 'quotation', 'lines' => $this->lines])->assertForbidden();
    $this->asToken($this->noCrm)->postJson(($this->api)("{$this->project['id']}/quotes"), ['kind' => 'quotation', 'lines' => $this->lines])->assertForbidden();
    $this->asToken($this->noCrm)->postJson(($this->api)("{$this->project['id']}/invoices"), ['issue_date' => '2026-11-05', 'lines' => $this->lines])->assertCreated();
    $this->asToken($this->noBill)->getJson(($this->api)("{$this->project['id']}/money"))->assertOk()->assertJsonPath('data.can.invoice', false);

    // Another tenant: not found.
    $other = orgToken(createMember($this->w->c4), $this->w->c4);
    $this->asToken($other)->getJson(($this->api)("{$this->project['id']}/money", $this->w->c4))->assertNotFound();

    // CRM and Accounting off: no quotes or invoices, the project still works.
    toggles()->disable($this->w->g1, 'crm', 'Test');
    toggles()->disable($this->w->g1, 'accounting', 'Test');
    $money = $this->asToken($this->manager)->getJson(($this->api)("{$this->project['id']}/money"))->assertOk()->json('data');
    expect($money['state'])->toMatchArray(['crm' => false, 'books' => false])
        ->and($money['can'])->toMatchArray(['quote' => false, 'invoice' => false])
        ->and($money['invoices'])->toBe([]);
    $this->asToken($this->manager)->postJson(($this->api)("{$this->project['id']}/quotes"), ['kind' => 'quotation', 'lines' => $this->lines])->assertForbidden();
    $this->asToken($this->manager)->postJson(($this->api)("{$this->project['id']}/tasks"), ['title' => 'Still works'])->assertCreated();
});
