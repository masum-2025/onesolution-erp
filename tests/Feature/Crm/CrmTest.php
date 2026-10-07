<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Notifications\Models\NotificationDelivery;
use Carbon\CarbonImmutable;
use Modules\Accounting\Models\Party;
use Modules\Accounting\Services\Books;
use Modules\Pos\Events\SaleMade;

/*
 * CRM-1: contacts (phone in E.164, duplicates refused, consent with its
 * time, removed on request), the company's own extra fields, pipelines by
 * sector and deals moving through them, follow-ups with reminders,
 * estimates and quotations (items and free lines, VAT, extra fields on the
 * quote and its lines) turned into an invoice, imports and audited exports,
 * branch and tenant isolation. POS-4: customers by mobile number at the
 * counter, with and without CRM, and loyalty points.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2] as $group) {
        foreach (['crm', 'accounting', 'inventory'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $role = fn (array $permissions, string $name, $unit = null) => orgToken(staffWithRoles($unit ?? $this->w->c1, makeRole($this->w->c1, $permissions, $name)), $unit ?? $this->w->c1);
    $this->managerUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['crm.view', 'crm.edit', 'crm.manage', 'crm.export', 'accounting.view', 'accounting.sell', 'accounting.manage', 'accounting.tax', 'inventory.view', 'inventory.manage'], 'Sales manager'));
    $this->manager = orgToken($this->managerUser, $this->w->c1);
    $this->sellerUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['crm.view', 'crm.edit'], 'Sales person'));
    $this->seller = orgToken($this->sellerUser, $this->w->c1);
    $this->viewer = $role(['crm.view'], 'Looker');
    setUpBooks($this, $this->manager, $this->w->c1)->assertCreated();
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/crm/{$path}";
    $this->contact = fn (array $data, ?string $token = null, $unit = null) => $this->asToken($token ?? $this->seller)->postJson(($this->api)('contacts', $unit), $data);
});

it('keeps contacts with one phone each, consent with its time, and removes a person\'s details on request', function () {
    $rahim = ($this->contact)(['name' => 'Rahim Uddin', 'phone' => '০১৭১১-০০০০০০', 'email' => 'Rahim@Example.com', 'sms_consent' => true, 'tags' => ['wholesale', 'wholesale', ' vip ']])
        ->assertCreated()->json('data');
    expect($rahim)->toMatchArray(['phone' => '+8801711000000', 'email' => 'rahim@example.com', 'sms_consent' => true, 'tags' => ['wholesale', 'vip']])
        ->and($rahim['consent_at'])->not->toBeNull();

    ($this->contact)(['name' => 'Same number', 'phone' => '+880 1711 000000'])->assertUnprocessable()->assertJsonValidationErrors('phone');
    ($this->contact)(['name' => 'Bad', 'phone' => '12'])->assertUnprocessable()->assertJsonValidationErrors('phone');
    ($this->contact)(['name' => 'X', 'unknown' => 1])->assertUnprocessable();
    // The same op id (an offline retry) is the same contact.
    $op = ['name' => 'Karim', 'op_id' => 'op-1'];
    $first = ($this->contact)($op)->assertCreated()->json('data.id');
    expect(($this->contact)($op)->assertOk()->json('data.id'))->toBe($first);

    $this->asToken($this->viewer)->getJson(($this->api)('contacts?q=01711000000'))->assertOk()->assertJsonPath('data.0.id', $rahim['id']);
    $this->asToken($this->viewer)->getJson(($this->api)('contacts?tag=vip'))->assertOk()->assertJsonCount(1, 'data');
    ($this->contact)(['name' => 'Viewer try'], $this->viewer)->assertForbidden();

    // Removing details needs crm.manage; amounts stay, details go, the audit says why but not what.
    $this->asToken($this->seller)->postJson(($this->api)("contacts/{$rahim['id']}/anonymize"), ['base_version' => $rahim['version'], 'reason' => 'Asked by phone'])->assertForbidden();
    $gone = $this->asToken($this->manager)->postJson(($this->api)("contacts/{$rahim['id']}/anonymize"), ['base_version' => $rahim['version'], 'reason' => 'Asked by phone'])->assertOk()->json('data');
    expect($gone)->toMatchArray(['phone' => null, 'email' => null, 'anonymized' => true, 'sms_consent' => false]);
    expect(json_encode(AuditLog::query()->where('action', 'like', 'crm.contact%')->get()->pluck('new_values')))->not->toContain('1711000000');
    $this->asToken($this->seller)->patchJson(($this->api)("contacts/{$rahim['id']}"), ['base_version' => $gone['version'], 'name' => 'Back'])->assertConflict()->assertJsonPath('code', 'anonymized');
    // The number is free again.
    ($this->contact)(['name' => 'New owner of the number', 'phone' => '01711000000'])->assertCreated();
});

it('lets a company add, change and switch off its own fields for contacts, deals, quotes and lines', function () {
    $field = fn (array $data, ?string $token = null) => $this->asToken($token ?? $this->manager)->postJson(($this->api)('fields'), $data);
    $field(['entity' => 'contact', 'key' => 'birthday', 'type' => 'date', 'label' => ['en' => 'Birthday']], $this->seller)->assertForbidden();
    $birthday = $field(['entity' => 'contact', 'key' => 'birthday', 'type' => 'date', 'label' => ['en' => 'Birthday', 'bn' => 'জন্মদিন']])->assertCreated()->json('data');
    $field(['entity' => 'contact', 'key' => 'birthday', 'type' => 'text', 'label' => ['en' => 'Again']])->assertUnprocessable()->assertJsonValidationErrors('key');
    $field(['entity' => 'contact', 'key' => 'segment', 'type' => 'choice', 'label' => ['en' => 'Segment'], 'is_required' => true,
        'options' => [['value' => 'retail', 'label' => ['en' => 'Retail']], ['value' => 'corporate', 'label' => ['en' => 'Corporate', 'bn' => 'কর্পোরেট']]]])->assertCreated();
    $field(['entity' => 'quote_line', 'key' => 'warranty_months', 'type' => 'number', 'label' => ['en' => 'Warranty (months)']])->assertCreated();
    $field(['entity' => 'quote', 'key' => 'delivery', 'type' => 'money', 'label' => ['en' => 'Delivery charge']])->assertCreated();

    ($this->contact)(['name' => 'No segment'])->assertUnprocessable()->assertJsonValidationErrors('extra.segment');
    ($this->contact)(['name' => 'Wrong', 'extra' => ['segment' => 'gold', 'birthday' => '31/02/2000', 'shoe_size' => 42]])
        ->assertUnprocessable()->assertJsonValidationErrors(['extra.segment', 'extra.birthday', 'extra.shoe_size']);
    $made = ($this->contact)(['name' => 'Typed', 'extra' => ['segment' => 'corporate', 'birthday' => '15/08/1990']])->assertCreated()->json('data');
    expect($made['extra'])->toEqual(['birthday' => '1990-08-15', 'segment' => 'corporate']);

    // Key and type are fixed; switching a field off keeps old values and stops asking for it.
    $this->asToken($this->manager)->patchJson(($this->api)("fields/{$birthday['id']}"), ['base_version' => 1, 'type' => 'text'])->assertUnprocessable();
    $this->asToken($this->manager)->patchJson(($this->api)("fields/{$birthday['id']}"), ['base_version' => 1, 'is_active' => false, 'label' => ['en' => 'Date of birth']])->assertOk()->assertJsonPath('data.is_active', false);
    expect($this->asToken($this->seller)->getJson(($this->api)("contacts/{$made['id']}"))->json('data.extra.birthday'))->toBe('1990-08-15');
    ($this->contact)(['name' => 'Later', 'extra' => ['segment' => 'retail', 'birthday' => '2000-01-01']])->assertUnprocessable()->assertJsonValidationErrors('extra.birthday');
    expect(collect($this->asToken($this->seller)->getJson(($this->api)('setup'))->assertOk()->json('data.fields.contact'))->pluck('key')->all())->toBe(['segment']);
    expect(AuditLog::query()->where('action', 'crm.field_updated')->count())->toBe(1);
});

it('moves deals through the company\'s pipeline: lost needs a reason, the won stage closes them', function () {
    $setup = $this->asToken($this->seller)->getJson(($this->api)('setup'))->assertOk()->json('data');
    $pipeline = $setup['pipelines'][0];
    // The sector's pipeline (data file), with one won and one lost stage.
    expect(collect($pipeline['stages'])->where('outcome', 'open')->count())->toBeGreaterThan(1)->and(collect($pipeline['stages'])->pluck('outcome')->filter(fn ($outcome) => $outcome !== 'open')->values()->all())->toBe(['won', 'lost']);
    // Stages by place, whatever the sector calls them: the first, second and last open ones, won and lost.
    $open = collect($pipeline['stages'])->where('outcome', 'open')->values();
    $stage = fn (string $key) => match ($key) {
        'new' => $open->first()['id'],
        'quoted' => $open[1]['id'],
        'negotiation' => $open->last()['id'],
        default => collect($pipeline['stages'])->firstWhere('outcome', $key)['id'],
    };

    $contact = ($this->contact)(['name' => 'Padma Textiles', 'kind' => 'organization'])->json('data.id');
    $deal = $this->asToken($this->seller)->postJson(($this->api)('deals'), ['contact_id' => $contact, 'title' => 'Uniforms for 200 staff', 'value_minor' => 45000000])->assertCreated()->json('data');
    expect($deal)->toMatchArray(['status' => 'open', 'stage_id' => $stage('new'), 'currency' => 'BDT', 'owner_id' => $this->sellerUser->id]);

    $move = fn (array $deal, string $to, array $extra = []) => $this->asToken($this->seller)->postJson(($this->api)("deals/{$deal['id']}/move"), ['base_version' => $deal['version'], 'stage_id' => $stage($to), ...$extra]);
    $deal = $move($deal, 'quoted')->assertOk()->json('data');
    $move($deal, 'lost')->assertUnprocessable()->assertJsonValidationErrors('lost_reason');
    $lost = $move($deal, 'lost', ['lost_reason' => 'Price too high'])->assertOk()->json('data');
    expect($lost)->toMatchArray(['status' => 'lost', 'lost_reason' => 'Price too high'])->and($lost['closed_at'])->not->toBeNull();
    $open = $move($lost, 'negotiation')->assertOk()->json('data');
    expect($open)->toMatchArray(['status' => 'open', 'lost_reason' => null, 'closed_at' => null]);
    $move($deal, 'won')->assertConflict()->assertJsonPath('code', 'version_conflict');

    $board = $this->asToken($this->viewer)->getJson(($this->api)('deals'))->assertOk();
    expect($board->json('data.0'))->toMatchArray(['id' => $deal['id'], 'contact_name' => 'Padma Textiles']);
    expect(AuditLog::query()->where('action', 'crm.deal_moved')->count())->toBe(3);

    // Stages are the company's: renamed, added; a stage with open deals stays on.
    $this->asToken($this->manager)->patchJson(($this->api)("pipelines/{$pipeline['id']}/stages/{$stage('negotiation')}"), ['base_version' => 1, 'is_active' => false])
        ->assertUnprocessable()->assertJsonValidationErrors('is_active');
    $this->asToken($this->manager)->postJson(($this->api)("pipelines/{$pipeline['id']}/stages"), ['name' => ['en' => 'Sample sent', 'bn' => 'নমুনা পাঠানো'], 'probability_bp' => 6000])->assertCreated();
    $this->asToken($this->seller)->postJson(($this->api)("pipelines/{$pipeline['id']}/stages"), ['name' => ['en' => 'Mine']])->assertForbidden();
});

it('gives follow-ups to people here, lists mine by day, and reminds each once before it is due', function () {
    $contact = ($this->contact)(['name' => 'Nasrin Akter', 'phone' => '01811000000'])->json('data.id');
    $call = fn (array $data) => $this->asToken($this->seller)->postJson(($this->api)('activities'), ['contact_id' => $contact, 'kind' => 'call', 'subject' => 'Call back about prices', ...$data]);
    $call(['due_at' => '2026-11-05T04:20:00Z', 'assigned_to' => $this->w->owner->id])->assertCreated();
    $call(['due_at' => '2026-11-05T09:00:00Z'])->assertCreated();
    $call(['due_at' => '2026-11-03T09:00:00Z'])->assertCreated();
    $call(['due_at' => '2026-11-05T09:00:00Z', 'assigned_to' => User::factory()->create()->id])->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
    $note = $call(['kind' => 'note', 'subject' => 'Prefers WhatsApp'])->assertCreated()->json('data');
    expect($note['done_at'])->not->toBeNull();

    $mine = $this->asToken($this->seller)->getJson(($this->api)('activities?when=today'))->assertOk();
    expect($mine->json('data'))->toHaveCount(1)->and($mine->json('meta'))->toBe(['overdue' => 1, 'today' => 1]);
    $this->asToken($this->seller)->getJson(($this->api)("activities?assigned_to={$this->w->owner->id}"))->assertForbidden();

    // 30 minutes before (the rule's default): the owner's call at 04:20 is due, and the seller's late one; each reminded once, not the 09:00 one.
    $this->artisan('crm:remind')->assertSuccessful();
    $this->artisan('crm:remind')->assertSuccessful();
    expect(NotificationDelivery::query()->where('notification_key', 'crm.follow_up_due')->where('user_id', $this->w->owner->id)->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('notification_key', 'crm.follow_up_due')->where('user_id', $this->sellerUser->id)->count())->toBe(1);

    $task = $mine->json('data.0');
    $this->asToken($this->seller)->patchJson(($this->api)("activities/{$task['id']}"), ['base_version' => $task['version'], 'done' => true])->assertOk()->assertJsonPath('data.version', 2);
    expect($this->asToken($this->seller)->getJson(($this->api)('activities?when=today'))->json('meta.today'))->toBe(0);
});

it('prices estimates and quotations with items, free lines, VAT and extra fields, then turns an accepted quotation into an invoice once', function () {
    $this->asToken($this->manager)->postJson(($this->api)('fields'), ['entity' => 'quote_line', 'key' => 'warranty_months', 'type' => 'number', 'label' => ['en' => 'Warranty (months)']])->assertCreated();
    $this->asToken($this->manager)->postJson(($this->api)('fields'), ['entity' => 'quote', 'key' => 'site', 'type' => 'text', 'label' => ['en' => 'Site address'], 'is_required' => true])->assertCreated();
    $vat = $this->asToken($this->manager)->postJson("/api/organizations/{$this->w->c1->id}/accounting/tax-codes", ['code' => 'V15', 'name' => ['en' => 'VAT 15%'], 'rate_bp' => 1500, 'kind' => 'standard', 'applies_to' => 'sales'])->assertCreated()->json('data.id');
    $inv = fn (string $path) => "/api/organizations/{$this->w->c1->id}/inventory/{$path}";
    $pcs = $this->asToken($this->manager)->postJson($inv('units'), ['code' => 'PCS', 'name' => ['en' => 'Pieces'], 'decimals' => 0])->json('data.id');
    $chair = $this->asToken($this->manager)->postJson($inv('items'), ['sku' => 'CHAIR', 'name' => ['en' => 'Office chair'], 'unit_id' => $pcs, 'kind' => 'stock', 'sale_price_minor' => 650000])->assertCreated()->json('data.id');

    $contact = ($this->contact)(['name' => 'Rahim', 'company_name' => 'Rahim Traders', 'phone' => '01911000000'])->json('data.id');
    $deal = $this->asToken($this->seller)->postJson(($this->api)('deals'), ['contact_id' => $contact, 'title' => 'Office fit-out'])->json('data');
    $lines = [
        ['item_id' => $chair, 'quantity_milli' => 10000, 'discount_minor' => 500000, 'tax_code_id' => $vat, 'extra' => ['warranty_months' => '12']],
        ['description' => 'Fitting and delivery', 'quantity_milli' => 1000, 'unit_price_minor' => 200000],
    ];
    $quote = fn (array $data) => $this->asToken($this->seller)->postJson(($this->api)('quotes'), ['kind' => 'estimate', 'contact_id' => $contact, 'deal_id' => $deal['id'], 'lines' => $lines, ...$data]);
    $quote([])->assertUnprocessable()->assertJsonValidationErrors('extra.site');
    $quote(['lines' => [['description' => 'X', 'quantity_milli' => 1000, 'unit_price_minor' => 100, 'extra' => ['warranty_months' => 'long']]], 'extra' => ['site' => 'Gulshan']])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.extra.warranty_months');
    $estimate = $quote(['extra' => ['site' => 'Gulshan 2, Dhaka']])->assertCreated()->json('data');
    // 10 chairs at 6,500 less 5,000 = 60,000 + 15% VAT 9,000; fitting 2,000 without VAT.
    expect($estimate)->toMatchArray(['number' => 'EST-2026-00001', 'status' => 'draft', 'subtotal_minor' => 6700000, 'discount_minor' => 500000, 'tax_minor' => 900000, 'total_minor' => 7100000, 'valid_until' => '2026-12-05'])
        ->and($estimate['lines'][0])->toMatchArray(['description' => 'CHAIR Office chair', 'unit' => 'PCS', 'unit_price_minor' => 650000, 'net_minor' => 6000000, 'tax_minor' => 900000])
        ->and((array) $estimate['lines'][0]['extra'])->toBe(['warranty_months' => '12'])
        ->and($estimate['can'])->toMatchArray(['convert' => true, 'accept' => false]);

    $step = fn (array $quote, string $step, array $data = [], ?string $token = null) => $this->asToken($token ?? $this->seller)->postJson(($this->api)("quotes/{$quote['id']}/{$step}"), ['base_version' => $quote['version'], ...$data]);
    $step($estimate, 'accept')->assertConflict();
    $quotation = $step($estimate, 'convert')->assertOk()->json('data');
    expect($quotation)->toMatchArray(['kind' => 'quotation', 'number' => 'QT-2026-00001', 'status' => 'draft', 'from_quote_id' => $estimate['id'], 'total_minor' => 7100000]);
    expect($this->asToken($this->seller)->getJson(($this->api)("quotes/{$estimate['id']}"))->json('data.status'))->toBe('converted');

    $sent = $step($quotation, 'send')->assertOk()->json('data');
    // Changing a sent quotation makes it a draft again.
    $changed = $this->asToken($this->seller)->patchJson(($this->api)("quotes/{$sent['id']}"), ['base_version' => $sent['version'], 'terms' => '50% advance'])->assertOk()->json('data');
    expect($changed['status'])->toBe('draft');
    // A seller without Accounting's selling permission cannot make the invoice; the manager can.
    $step($changed, 'accept', ['invoice' => true])->assertForbidden();
    $accepted = $step($changed, 'accept', ['invoice' => true], $this->manager)->assertOk()->json('data');
    expect($accepted['status'])->toBe('accepted')->and($accepted['invoice_id'])->not->toBeNull();
    $step($accepted, 'accept', ['invoice' => true], $this->manager)->assertConflict()->assertJsonPath('code', 'wrong_status');

    // The deal is won; the customer made once with the contact's id; the invoice a draft at the same total.
    expect($this->asToken($this->seller)->getJson(($this->api)('deals?status=won'))->json('data.0.id'))->toBe($deal['id']);
    $party = app(Books::class)->query(Party::class, $this->w->c1)->where('crm_contact_id', $contact)->sole();
    expect($party)->toMatchArray(['name' => 'Rahim Traders', 'is_customer' => true]);
    $invoice = $this->asToken($this->manager)->getJson("/api/organizations/{$this->w->c1->id}/accounting/documents/{$accepted['invoice_id']}")->assertOk()->json('data');
    expect($invoice)->toMatchArray(['type' => 'invoice', 'status' => 'draft', 'reference' => 'QT-2026-00001', 'total_minor' => 7100000]);
    $this->asToken($this->manager)->postJson(($this->api)("contacts/{$contact}/customer"))->assertOk()->assertJsonPath('data.party_id', $party->id);
    expect($this->asToken($this->seller)->getJson(($this->api)("contacts/{$contact}"))->json('data.books.party_id'))->toBe($party->id);
});

it('imports contacts after a check (duplicates skipped) and exports them only with the permission, audited', function () {
    ($this->contact)(['name' => 'Known', 'phone' => '01711000001']);
    $rows = [
        ['name' => 'Ayesha', 'phone' => '01711000002', 'tags' => 'school;parent'],
        ['name' => 'Known again', 'phone' => '01711000001'],
        ['name' => 'Twice', 'phone' => '01711000002'],
        ['name' => '', 'phone' => '0'],
    ];
    $check = $this->asToken($this->seller)->postJson(($this->api)('contacts/import'), ['commit' => false, 'rows' => $rows])->assertOk()->json('data');
    expect($check)->toMatchArray(['ok' => 1, 'duplicates' => 2, 'errors' => 1, 'made' => 0]);
    $done = $this->asToken($this->seller)->postJson(($this->api)('contacts/import'), ['commit' => true, 'rows' => $rows])->assertOk()->json('data');
    expect($done['made'])->toBe(1);
    expect($this->asToken($this->seller)->getJson(($this->api)('contacts?tag=parent'))->json('data.0.name'))->toBe('Ayesha');

    $this->asToken($this->seller)->get(($this->api)('contacts/export'))->assertForbidden();
    $csv = $this->asToken($this->manager)->get(($this->api)('contacts/export'))->assertOk()->streamedContent();
    expect($csv)->toContain('Ayesha')->toContain('+8801711000002');
    expect(AuditLog::query()->where('action', 'crm.contacts_exported')->sole()->new_values['count'])->toBe(2);
});

it('keeps branches, companies and partners apart, and refuses with the module off', function () {
    $branchUser = staffWithRoles($this->w->b1, makeRole($this->w->c1, ['crm.view', 'crm.edit'], 'Branch sales'));
    $branch = orgToken($branchUser, $this->w->b1);
    $atCompany = ($this->contact)(['name' => 'Head office customer'])->json('data.id');
    $atBranch = ($this->contact)(['name' => 'Branch customer'], $branch, $this->w->b1)->assertCreated()->json('data');
    expect($atBranch['unit_id'])->toBe($this->w->b1->id);

    expect(collect($this->asToken($branch)->getJson(($this->api)('contacts', $this->w->b1))->json('data'))->pluck('name')->all())->toBe(['Branch customer']);
    $this->asToken($branch)->getJson(($this->api)("contacts/{$atCompany}", $this->w->b1))->assertNotFound();
    // The company sees its branches' contacts.
    expect($this->asToken($this->seller)->getJson(($this->api)('contacts'))->json('meta.total'))->toBe(2);

    $other = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['crm.view', 'crm.edit'], 'Elsewhere')), $this->w->c2);
    $this->asToken($other)->getJson(($this->api)("contacts/{$atCompany}", $this->w->c2))->assertNotFound();
    $this->asToken($other)->getJson(($this->api)('contacts', $this->w->c1))->assertNotFound();
    toggles()->enable($this->w->g3, 'crm', 'Test setup');
    $partnerB = orgToken(staffWithRoles($this->w->c4, makeRole($this->w->c4, ['crm.view'], 'Partner B')), $this->w->c4);
    $this->asToken($partnerB)->getJson(($this->api)("contacts/{$atCompany}", $this->w->c4))->assertNotFound();

    toggles()->disable($this->w->g1, 'crm', 'Test');
    $this->asToken($this->seller)->getJson(($this->api)('contacts'))->assertForbidden();
});

it('finds counter customers by mobile number without CRM, and with CRM links them and gives points once per sale', function () {
    foreach (['pos'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    $inv = fn (string $path) => "/api/organizations/{$this->w->c1->id}/inventory/{$path}";
    $pos = fn (string $path) => "/api/organizations/{$this->w->c1->id}/pos/{$path}";
    $cashierUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['pos.view', 'pos.sell', 'pos.supervise', 'pos.manage', 'inventory.view', 'inventory.manage'], 'Counter'));
    $cashier = orgToken($cashierUser, $this->w->c1);
    $pcs = $this->asToken($cashier)->postJson($inv('units'), ['code' => 'PCS', 'name' => ['en' => 'Pieces'], 'decimals' => 0])->json('data.id');
    $shop = $this->asToken($cashier)->postJson($inv('warehouses'), ['code' => 'SHOP', 'name' => ['en' => 'Shop'], 'unit_id' => $this->w->c1->id])->json('data.id');
    $soap = $this->asToken($cashier)->postJson($inv('items'), ['sku' => 'SOAP', 'name' => ['en' => 'Soap'], 'unit_id' => $pcs, 'kind' => 'stock', 'sale_price_minor' => 25000])->json('data.id');
    $receipt = $this->asToken($cashier)->postJson($inv('documents'), ['type' => 'receipt', 'warehouse_id' => $shop, 'document_date' => '2026-11-04', 'lines' => [['item_id' => $soap, 'quantity_milli' => 50000, 'unit_cost_minor' => 15000]]])->json('data');
    $this->asToken($cashier)->postJson($inv("documents/{$receipt['id']}/post"), ['base_version' => $receipt['version']])->assertOk();
    $till = $this->asToken($cashier)->postJson($pos('registers'), ['code' => 'T1', 'name' => ['en' => 'Till'], 'unit_id' => $this->w->c1->id, 'warehouse_id' => $shop, 'payment_methods' => ['cash']])->json('data.id');
    $this->asToken($cashier)->postJson($pos("registers/{$till}/open"), ['opening_float_minor' => 0])->assertCreated();
    $sell = fn (array $extra, int $pieces = 4) => $this->asToken($cashier)->postJson($pos('sales'), ['op_id' => (string) str()->ulid(), 'register_id' => $till,
        'lines' => [['item_id' => $soap, 'quantity_milli' => $pieces * 1000]], 'payments' => [['method' => 'cash', 'amount_minor' => $pieces * 25000]], ...$extra]);

    // Without CRM: the number finds the earlier sales.
    toggles()->disable($this->w->g1, 'crm', 'Test');
    $sell(['customer_name' => 'Shathi', 'customer_phone' => '01711-222333'])->assertCreated()->assertJsonPath('data.customer_phone', '+8801711222333');
    $sell(['customer_phone' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('customer_phone');
    $this->asToken($cashier)->getJson($pos('customers?phone=০১৭১১২২২৩৩৩'))->assertOk()
        ->assertJsonPath('data', ['found' => true, 'source' => 'sales', 'id' => null, 'phone' => '+8801711222333', 'name' => 'Shathi', 'purchases' => 1, 'last_purchase_on' => '2026-11-05', 'points' => null]);
    expect($this->asToken($cashier)->getJson($pos('sales?phone=01711222333'))->json('data'))->toHaveCount(1);

    // With CRM: 2 points per 100 taka; the contact is made at the counter and gets the points; a return takes its share back.
    toggles()->enable($this->w->g1, 'crm', 'Test');
    trustedOrgRule($this->w->c1, 'crm.loyalty_points_per_100', 2);
    $sale = $sell(['customer_phone' => '01711222333'], 10)->assertCreated()->json('data');
    expect($sale['customer_name'])->toBe('+8801711222333')->and($sale['customer_id'])->not->toBeNull();
    $found = $this->asToken($cashier)->getJson($pos('customers?phone=01711222333'))->assertOk()->json('data');
    expect($found)->toMatchArray(['found' => true, 'source' => 'crm', 'id' => $sale['customer_id'], 'points' => 50, 'purchases' => 1]);
    $line = $this->asToken($cashier)->getJson($pos("sales/{$sale['id']}"))->json('data.lines.0.id');
    $this->asToken($cashier)->postJson($pos("sales/{$sale['id']}/return"), ['op_id' => (string) str()->ulid(), 'register_id' => $till, 'reason' => 'Changed mind', 'lines' => [['line_id' => $line, 'quantity_milli' => 4000]]])->assertCreated();
    $contact = $this->asToken($this->seller)->getJson(($this->api)("contacts/{$sale['customer_id']}"))->assertOk()->json('data');
    expect($contact)->toMatchArray(['points' => 30, 'spent_minor' => 150000, 'purchases' => 1, 'source' => 'pos']);
    // The same sale again (an offline retry) adds nothing.
    event(new SaleMade($this->w->c1->id, $sale['id'], 'sale', $sale['customer_id'], 250000, 'BDT', '2026-11-05'));
    expect($this->asToken($this->seller)->getJson(($this->api)("contacts/{$sale['customer_id']}"))->json('data.points'))->toBe(30);
});
