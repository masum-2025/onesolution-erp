<?php

use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleTargets;
use App\Platform\Tenancy\Context\CurrentContext;
use Carbon\CarbonImmutable;

/*
 * ACC-2: what the Accounting screens read besides the ACC-1 API: dashboard
 * widgets, the bell, the menu, and a personal workspace keeping its own books.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 06:00:00', 'UTC'));
    $this->w = accountingWorld();
    setUpBooks($this, $this->w->token, $this->w->c1)->assertCreated();
    $this->base = '/api/modules/accounting/dashboard';
});

it('shows income and expenses of this month against the same days last month', function () {
    trustedOrgRule($this->w->c1, 'accounting.allow_backdated_entries_days', 366);
    $post = fn (array $lines, string $date) => journalVia($this, $this->w->token, $this->w->c1, $lines, ['submit' => true, 'entry_date' => $date])->assertCreated();
    $post([['1110', 300000, 0], ['4100', 0, 300000]], '2026-10-02');
    $post([['1110', 100000, 0], ['4100', 0, 100000]], '2026-09-10');
    // After the 15th of September: not part of the comparison.
    $post([['1110', 900000, 0], ['4100', 0, 900000]], '2026-09-20');
    $post([['5300', 50000, 0], ['1110', 0, 50000]], '2026-10-05');

    $income = $this->asToken($this->w->viewerToken)->getJson("{$this->base}/income")->assertOk()->json('data');
    expect($income)->toMatchArray(['value' => 300000, 'format' => 'money', 'currency' => 'BDT'])
        ->and($income['change'])->toBe(['value' => 200000, 'direction' => 'up', 'tone' => 'good'])
        ->and($income['series'])->toBe([0, 0, 0, 0, 1000000, 300000]);

    $expenses = $this->asToken($this->w->viewerToken)->getJson("{$this->base}/expenses")->assertOk()->json('data');
    expect($expenses['value'])->toBe(50000)->and($expenses['change']['tone'])->toBe('bad');

    $recent = $this->asToken($this->w->viewerToken)->getJson("{$this->base}/recent")->assertOk()->json('data.items');
    expect($recent)->toHaveCount(4)
        ->and($recent[0])->toMatchArray(['meta' => 'JV-2026-00004', 'date' => '2026-10-05'])
        ->and($recent[0]['path'])->toStartWith('/accounting/journals/');
});

it('counts entries waiting for approval, and rings the bell only for other people\'s entries', function () {
    trustedOrgRule($this->w->c1, 'accounting.journal_approval_above', ['amount' => 0, 'currency' => 'BDT']);
    journalVia($this, $this->w->token, $this->w->c1, [['5500', 100, 0], ['1110', 0, 100]], ['submit' => true])->assertCreated();

    $this->asToken($this->w->viewerToken)->getJson("{$this->base}/waiting")->assertOk()->assertJsonPath('data.value', 1);
    $this->asToken($this->w->approverToken)->getJson('/api/attention')->assertOk()->assertJsonFragment([
        'key' => 'accounting.journals_waiting', 'label' => 'Accounting entries to approve', 'count' => 1, 'path' => '/accounting/approvals', 'tone' => 'warn',
    ]);
    // The writer has no approval right; an approver who wrote it sees nothing to do.
    expect(collect($this->asToken($this->w->token)->getJson('/api/attention')->json('data'))->pluck('key'))->not->toContain('accounting.journals_waiting');
});

it('shows empty widgets where no books are kept', function () {
    $b1Token = orgToken(createMember($this->w->b1), $this->w->b1);
    $this->asToken($b1Token)->getJson("{$this->base}/income")->assertOk()->assertJsonPath('data.value', 0)->assertJsonPath('data.hint', 'Set up the books to see this');
    $this->asToken($b1Token)->getJson("{$this->base}/recent")->assertOk()->assertJsonPath('data.items', []);
});

it('puts Accounting under Finance in the menu, with the screens the person may open', function () {
    $menu = collect($this->asToken($this->w->viewerToken)->getJson('/api/menu')->assertOk()->json('data'))->firstWhere('key', 'accounting');
    expect($menu['section'])->toBe('finance')
        ->and($menu['section_label'])->toBe('Finance')
        ->and(collect($menu['children'])->pluck('key')->all())->not->toContain('approvals')
        ->and(collect($menu['children'])->pluck('key')->all())->toContain('reports');

    $approverMenu = collect($this->asToken($this->w->approverToken)->getJson('/api/menu')->json('data'))->firstWhere('key', 'accounting');
    expect(collect($approverMenu['children'])->pluck('key')->all())->toContain('approvals');
});

it('lets the one person of a personal workspace keep their own books', function () {
    $self = selfServeWorld();
    toggles()->enable($self->workspace, 'accounting', 'Test setup');
    // Personal plans need no second person (database/seeders/data/rule-values.php).
    foreach (['personal_free', 'personal_plus'] as $personal) {
        ruleService()->set(app(RuleTargets::class)->plan($personal), 'access.separation_of_duties', RuleMode::Set, [], 'Test setup', trusted: true);
    }
    app(CurrentContext::class)->clear();
    $token = orgToken($self->user, $self->workspace);

    setUpBooks($this, $token, $self->workspace)->assertCreated();
    $posted = journalVia($this, $token, $self->workspace, [['1110', 5000, 0], ['3100', 0, 5000]], ['submit' => true])->assertCreated()->json('data');
    expect($posted['status'])->toBe('posted');

    // A business plan keeps the pair: an owner there cannot write entries without a role.
    $ownerToken = orgToken(createMember($this->w->c1), $this->w->c1);
    journalVia($this, $ownerToken, $this->w->c1, [['1110', 5000, 0], ['3100', 0, 5000]])->assertForbidden();
});

it('shows what customers owe (and how much is overdue) and what is owed to vendors', function () {
    trustedOrgRule($this->w->c1, 'accounting.allow_backdated_entries_days', 366);
    trustedOrgRule($this->w->c1, 'accounting.allow_overpayment', true);
    $clerk = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['accounting.view', 'accounting.sell', 'accounting.buy'], 'Clerk')), $this->w->c1);
    $api = fn (string $path) => "/api/organizations/{$this->w->c1->id}/accounting/{$path}";
    $customer = $this->asToken($clerk)->postJson($api('parties'), ['name' => 'Karim Traders', 'is_customer' => true, 'payment_terms_days' => 0])->json('data.id');
    $vendor = $this->asToken($clerk)->postJson($api('parties'), ['name' => 'Paper Mills', 'is_vendor' => true])->json('data.id');
    $line = fn (string $code, int $price) => [['description' => 'Item', 'quantity' => '1', 'unit_price_minor' => $price, 'account_id' => accountId($this->w->c1, $code)]];

    $this->asToken($clerk)->postJson($api('documents'), ['type' => 'invoice', 'party_id' => $customer, 'issue_date' => '2026-09-01', 'lines' => $line('4100', 40000), 'submit' => true])->assertCreated();
    $this->asToken($clerk)->postJson($api('documents'), ['type' => 'invoice', 'party_id' => $customer, 'issue_date' => '2026-10-15', 'due_date' => '2026-11-15', 'lines' => $line('4100', 10000), 'submit' => true])->assertCreated();
    $this->asToken($clerk)->postJson($api('settlements'), ['type' => 'receipt', 'party_id' => $customer, 'settled_on' => '2026-10-15', 'account_id' => accountId($this->w->c1, '1110'), 'amount_minor' => 5000])->assertCreated();
    $this->asToken($clerk)->postJson($api('documents'), ['type' => 'bill', 'party_id' => $vendor, 'issue_date' => '2026-10-10', 'lines' => $line('5500', 7000), 'submit' => true])->assertCreated();

    $this->asToken($this->w->viewerToken)->getJson("{$this->base}/customers_owe")->assertOk()
        ->assertJsonPath('data.value', 45000)->assertJsonPath('data.format', 'money')->assertJsonPath('data.hint', 'Open invoices less credits and advances');
    $this->asToken($this->w->viewerToken)->getJson("{$this->base}/customers_overdue")->assertOk()->assertJsonPath('data.value', 40000);
    $this->asToken($this->w->viewerToken)->getJson("{$this->base}/vendors_owed")->assertOk()->assertJsonPath('data.value', 7000);
});

it('rings the bell for documents and money waiting for approval, never for the approver\'s own', function () {
    trustedOrgRule($this->w->c1, 'accounting.journal_approval_above', ['amount' => 0, 'currency' => 'BDT']);
    $clerk = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['accounting.view', 'accounting.sell'], 'Clerk')), $this->w->c1);
    $api = fn (string $path) => "/api/organizations/{$this->w->c1->id}/accounting/{$path}";
    $customer = $this->asToken($clerk)->postJson($api('parties'), ['name' => 'Karim Traders', 'is_customer' => true])->json('data.id');
    $this->asToken($clerk)->postJson($api('documents'), [
        'type' => 'invoice', 'party_id' => $customer, 'issue_date' => '2026-10-15', 'submit' => true,
        'lines' => [['description' => 'Item', 'quantity' => '1', 'unit_price_minor' => 100, 'account_id' => accountId($this->w->c1, '4100')]],
    ])->assertCreated()->assertJsonPath('data.status', 'pending_approval');
    journalVia($this, $this->w->token, $this->w->c1, [['5500', 100, 0], ['1110', 0, 100]], ['submit' => true])->assertCreated();

    $this->asToken($this->w->viewerToken)->getJson("{$this->base}/waiting")->assertJsonPath('data.value', 2);
    $this->asToken($this->w->approverToken)->getJson('/api/attention')->assertJsonFragment(['key' => 'accounting.journals_waiting', 'count' => 2, 'label' => 'Accounting entries to approve']);
});

it('adds Sales and Purchases to the Finance menu', function () {
    $menu = collect($this->asToken($this->w->viewerToken)->getJson('/api/menu')->assertOk()->json('data'))->keyBy('key');
    expect($menu['sales']['section'])->toBe('finance')
        ->and(collect($menu['sales']['children'])->pluck('route')->all())->toBe(['/accounting/customers', '/accounting/sales', '/accounting/receipts'])
        ->and(collect($menu['purchases']['children'])->pluck('route')->all())->toBe(['/accounting/vendors', '/accounting/purchases', '/accounting/payments']);
});
