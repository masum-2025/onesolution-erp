<?php

use App\Models\User;
use App\Platform\Payments\GatewayRegistry;
use App\Platform\Payments\Models\MerchantAccount;
use App\Platform\Payments\Models\Payment;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\Settlement;

/*
 * ACC-3c: a customer sees their own invoices in the client's portal and
 * pays them online into the company's own merchant account; the money
 * becomes a receipt against the invoice.
 */

const PORTAL_STORE = 'schoolstore01';
const PORTAL_STORE_PASSWORD = 'school-store-password';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 06:00:00', 'UTC'));
    $this->w = accountingWorld();
    foreach (['client_portal', 'online_payments'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    platformRule('online_payments.gateways', ['sslcommerz'], 'BD');
    app()->forgetInstance(GatewayRegistry::class);
    orgRule($this->w->c1, 'client_portal.allow_online_payment', true);
    setUpBooks($this, $this->w->token, $this->w->c1)->assertCreated();

    $this->clerk = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['accounting.view', 'accounting.sell'], 'Clerk')), $this->w->c1);
    $this->api = fn (string $path) => "/api/organizations/{$this->w->c1->id}/accounting/{$path}";
    $party = fn (string $name) => $this->asToken($this->clerk)->postJson(($this->api)('parties'), ['name' => $name, 'is_customer' => true])->json('data.id');
    $this->rahim = $party('Rahim Uddin');
    $this->nasrin = $party('Nasrin Akter');
    $this->invoice = fn (string $partyId, int $price, array $data = []) => $this->asToken($this->clerk)->postJson(($this->api)('documents'), [
        'type' => 'invoice', 'party_id' => $partyId, 'issue_date' => '2026-10-15', 'submit' => true,
        'lines' => [['description' => 'Tuition fee October', 'quantity' => '1', 'unit_price_minor' => $price, 'account_id' => accountId($this->w->c1, '4100')]],
        ...$data,
    ])->assertCreated()->json('data');

    // Rahim's guardian in the portal, linked to Rahim's customer record.
    $this->guardian = User::factory()->create(['email_verified_at' => now()]);
    app(AddMember::class)->handle($this->w->c1, $this->guardian, MembershipType::Portal, AccessScope::Own);
    $membership = OrganizationMembership::query()->where('user_id', $this->guardian->id)->where('organization_id', $this->w->c1->id)->sole();
    (new PortalLink)->forceFill([
        'organization_id' => $this->w->c1->id, 'membership_id' => $membership->id, 'user_id' => $this->guardian->id,
        'subject_type' => 'accounting.customer', 'subject_id' => $this->rahim, 'relation' => 'self', 'status' => PortalLink::ACTIVE, 'linked_via' => 'invitation',
    ])->save();
    $this->portal = orgToken($this->guardian, $this->w->c1);
    $this->withHeader('Origin', config('app.url'));
});

/** The school's own sandbox store, active, and a gateway that answers for it. */
function schoolStore(object $test): void
{
    (new MerchantAccount)->forceFill([
        'organization_id' => $test->w->c1->id, 'gateway' => 'sslcommerz', 'label' => 'School store', 'currency_code' => 'BDT',
        'status' => MerchantAccount::ACTIVE, 'mode' => 'sandbox', 'credentials' => ['store_id' => PORTAL_STORE, 'store_password' => PORTAL_STORE_PASSWORD], 'version' => 1,
    ])->save();

    Http::fake(function (HttpRequest $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        if (str_contains($request->url(), '/gwprocess/')) {
            return Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://sandbox.sslcommerz.com/EasyCheckOut/'.$request->data()['tran_id'], 'sessionkey' => 'SK1']);
        }
        if (str_contains($request->url(), 'validationserverAPI.php')) {
            $payment = Payment::query()->find(substr((string) ($query['val_id'] ?? ''), 4));
            $amount = number_format($payment->amount_minor / 100, 2, '.', '');

            return Http::response(['status' => 'VALID', 'tran_id' => $payment->id, 'val_id' => $query['val_id'], 'amount' => $amount, 'currency' => 'BDT', 'currency_type' => 'BDT', 'currency_amount' => $amount, 'risk_level' => '0', 'store_id' => PORTAL_STORE]);
        }

        return Http::response('Unexpected', 500);
    });
}

it('shows a portal customer their own posted invoices, and nobody else\'s', function () {
    $mine = ($this->invoice)($this->rahim, 350000);
    $theirs = ($this->invoice)($this->nasrin, 300000);
    $this->asToken($this->clerk)->postJson(($this->api)('documents'), [
        'type' => 'invoice', 'party_id' => $this->rahim, 'issue_date' => '2026-10-15',
        'lines' => [['description' => 'Draft', 'quantity' => '1', 'unit_price_minor' => 1, 'account_id' => accountId($this->w->c1, '4100')]],
    ])->assertCreated();

    $list = $this->asToken($this->portal)->getJson('/api/portal/accounting/invoices')->assertOk()->json('data');
    expect(array_column($list['documents'], 'id'))->toBe([$mine['id']])
        ->and($list['customers'])->toBe([['id' => $this->rahim, 'name' => 'Rahim Uddin', 'balance_minor' => 350000]])
        ->and($list['documents'][0])->toMatchArray(['number' => 'INV-2026-00001', 'balance_minor' => 350000, 'due_date' => '2026-11-14']);

    $one = $this->asToken($this->portal)->getJson("/api/portal/accounting/invoices/{$mine['id']}")->assertOk()->json('data');
    expect($one['lines'][0])->toBe(['description' => 'Tuition fee October', 'quantity' => '1', 'unit_price_minor' => 350000, 'amount_minor' => 350000, 'tax_rate_bp' => 0, 'tax_minor' => 0])
        ->and(json_encode($one))->not->toContain('account');

    // Another customer's invoice, by guessing its id: the same not found.
    $this->asToken($this->portal)->getJson("/api/portal/accounting/invoices/{$theirs['id']}")->assertNotFound();
    $this->asToken($this->portal)->getJson('/api/portal/accounting/invoices?customer='.$this->nasrin)->assertOk()->assertJsonPath('data.documents', []);
    // The staff API stays closed to portal members; staff get nothing from the portal API.
    $this->asToken($this->portal)->getJson(($this->api)('documents'))->assertForbidden();
    expect($this->asToken($this->clerk)->getJson('/api/portal/accounting/invoices')->json('data.documents'))->toBe([]);
});

it('opens the customer\'s invoices from the portal home', function () {
    $home = $this->asToken($this->portal)->getJson('/api/portal')->assertOk()->json('data.records');
    expect($home)->toHaveCount(1)
        ->and($home[0])->toMatchArray(['kind' => 'Customer', 'name' => 'Rahim Uddin', 'page' => "/portal/invoices?customer={$this->rahim}"]);
});

it('takes an online payment into the school\'s store and records a receipt against the invoice', function () {
    schoolStore($this);
    $invoice = ($this->invoice)($this->rahim, 350000);
    expect($this->asToken($this->portal)->getJson("/api/portal/accounting/invoices/{$invoice['id']}")->json('data.can_pay'))->toBeTrue();

    $opId = (string) Str::ulid();
    $start = $this->asToken($this->portal)->postJson("/api/portal/accounting/invoices/{$invoice['id']}/pay", ['op_id' => $opId])->assertCreated()->json('data');
    expect($start)->toMatchArray(['amount_minor' => 350000, 'currency' => 'BDT'])
        ->and($start['checkout_url'])->toStartWith('https://sandbox.sslcommerz.com/');
    // One click, one payment.
    $this->asToken($this->portal)->postJson("/api/portal/accounting/invoices/{$invoice['id']}/pay", ['op_id' => $opId])->assertCreated()->assertJsonPath('data.payment_id', $start['payment_id']);

    $payment = Payment::query()->findOrFail($start['payment_id']);
    $this->post('/payments/sslcommerz/notify', sslNotice($payment, password: PORTAL_STORE_PASSWORD))->assertOk();
    // The gateway may say it twice.
    $this->post('/payments/sslcommerz/notify', sslNotice($payment, password: PORTAL_STORE_PASSWORD))->assertOk();

    $receipt = Settlement::query()->sole();
    expect($receipt->only(['type', 'amount_minor', 'allocated_minor', 'op_id', 'created_by']))->toMatchArray(['amount_minor' => 350000, 'allocated_minor' => 350000, 'op_id' => "payment-{$payment->id}", 'created_by' => null])
        ->and($receipt->status->value)->toBe('posted')
        ->and($receipt->account_id)->toBe(accountId($this->w->c1, '1130'))
        ->and(Document::query()->findOrFail($invoice['id'])->status->value)->toBe('paid');

    $this->post('/payments/sslcommerz/return/success', sslNotice($payment, password: PORTAL_STORE_PASSWORD))->assertRedirect("/portal/invoices/{$invoice['id']}");
    expect($this->asToken($this->portal)->getJson("/api/portal/accounting/invoices/{$invoice['id']}")->json('data'))->toMatchArray(['status' => 'paid', 'can_pay' => false]);
});

it('only lets the linked customer pay, what the invoice says, while the school allows it', function () {
    schoolStore($this);
    $theirs = ($this->invoice)($this->nasrin, 300000);
    $mine = ($this->invoice)($this->rahim, 350000);

    $this->asToken($this->portal)->postJson("/api/portal/accounting/invoices/{$theirs['id']}/pay", ['op_id' => (string) Str::ulid()])->assertNotFound();
    // The amount never comes from the request.
    $this->asToken($this->portal)->postJson("/api/portal/accounting/invoices/{$mine['id']}/pay", ['op_id' => (string) Str::ulid(), 'amount_minor' => 1])
        ->assertCreated()->assertJsonPath('data.amount_minor', 350000);

    orgRule($this->w->c1, 'client_portal.allow_online_payment', false);
    $this->asToken($this->portal)->postJson("/api/portal/accounting/invoices/{$mine['id']}/pay", ['op_id' => (string) Str::ulid()])->assertStatus(422);
    expect($this->asToken($this->portal)->getJson("/api/portal/accounting/invoices/{$mine['id']}")->json('data.can_pay'))->toBeFalse();
});

it('keeps money paid online that is more than still due as an advance', function () {
    schoolStore($this);
    $invoice = ($this->invoice)($this->rahim, 350000);
    $payment = Payment::query()->findOrFail($this->asToken($this->portal)->postJson("/api/portal/accounting/invoices/{$invoice['id']}/pay", ['op_id' => (string) Str::ulid()])->json('data.payment_id'));

    // Meanwhile the front desk takes 100 in cash.
    $this->asToken($this->clerk)->postJson(($this->api)('settlements'), [
        'type' => 'receipt', 'party_id' => $this->rahim, 'settled_on' => '2026-10-15', 'account_id' => accountId($this->w->c1, '1110'),
        'amount_minor' => 10000, 'allocations' => [['document_id' => $invoice['id'], 'amount_minor' => 10000]],
    ])->assertCreated();

    $this->post('/payments/sslcommerz/notify', sslNotice($payment, password: PORTAL_STORE_PASSWORD))->assertOk();
    $online = Settlement::query()->where('op_id', "payment-{$payment->id}")->sole();
    expect([$online->amount_minor, $online->allocated_minor])->toBe([350000, 340000])
        ->and(Document::query()->findOrFail($invoice['id'])->status->value)->toBe('paid');
});
