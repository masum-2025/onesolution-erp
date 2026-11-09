<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Payments\GatewayRegistry;
use App\Platform\Payments\Models\MerchantAccount;
use App\Platform\Payments\Models\Payment;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleTargets;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\OnlineOrders\Models\Order;
use Modules\OnlineOrders\Models\OrderPayment;
use Modules\SocialCommerce\Models\SocialPage;
use Modules\SocialCommerce\Services\Pages;

/*
 * SC-5a: after a Messenger order the customer gets a payment link (rule
 * social_commerce.send_pay_link) for what Online orders asks online
 * (online_orders.online_payment: the delivery charge by default, or all).
 * The link opens a page without sign-in; it is signed (company + order),
 * ends after online_orders.pay_link_hours, and the amount is always the
 * order's own. The money goes to the company's own merchant account and is
 * kept on the order (method online, nobody typed it); the customer is
 * thanked in Messenger. Staff can copy a link from the order.
 */

require_once __DIR__.'/helpers.php';

const SHOP_STORE = 'bbushop01';
const SHOP_STORE_PASSWORD = 'bbu-shop-store-password';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        setPlan($group, 'business');
        foreach (['inventory', 'social_commerce', 'online_orders', 'online_payments'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    platformRule('online_payments.gateways', ['sslcommerz'], 'BD');
    app()->forgetInstance(GatewayRegistry::class);
    $this->metaApp = activeMetaApp($this->w->partnerA);
    $role = fn (array $permissions, string $name, $unit = null) => staffWithRoles($unit ?? $this->w->c1, makeRole($unit ?? $this->w->c1, $permissions, $name));
    $keeper = orgToken($role(['inventory.view', 'inventory.manage'], 'Store keeper'), $this->w->c1);
    $this->desk = orgToken($role(['online_orders.view', 'online_orders.manage'], 'Order desk'), $this->w->c1);
    $this->viewer = orgToken($role(['online_orders.view'], 'Order viewer'), $this->w->c1);
    $this->otherDesk = orgToken($role(['online_orders.view', 'online_orders.manage'], 'Order desk', $this->w->c2), $this->w->c2);

    $inv = fn (string $path) => "/api/organizations/{$this->w->c1->id}/inventory/{$path}";
    $pcs = $this->asToken($keeper)->postJson($inv('units'), ['code' => 'PCS', 'name' => ['en' => 'Pieces'], 'decimals' => 0])->assertCreated()->json('data');
    $store = $this->asToken($keeper)->postJson($inv('warehouses'), ['code' => 'WEB', 'name' => ['en' => 'Web store'], 'unit_id' => $this->w->c1->id])->assertCreated()->json('data');
    $soap = $this->asToken($keeper)->postJson($inv('items'), ['unit_id' => $pcs['id'], 'kind' => 'stock', 'sku' => 'SOAP', 'name' => ['en' => 'Soap', 'bn' => 'সাবান'], 'sale_price_minor' => 11500])->assertCreated()->json('data');
    $receipt = $this->asToken($keeper)->postJson($inv('documents'), ['type' => 'receipt', 'warehouse_id' => $store['id'], 'document_date' => '2026-11-01', 'lines' => [
        ['item_id' => $soap['id'], 'quantity_milli' => 5000, 'unit_cost_minor' => 6000],
    ]])->assertCreated()->json('data');
    $this->asToken($keeper)->postJson($inv("documents/{$receipt['id']}/post"), ['base_version' => $receipt['version']])->assertOk();
    $this->rule = fn (string $key, mixed $value, $unit = null) => ruleService()->set(app(RuleTargets::class)->organization(($unit ?? $this->w->c1)->fresh()), $key, RuleMode::Set, $value, 'Test setup', trusted: true);
    ($this->rule)('online_orders.delivery_zones', [
        ['code' => 'dhaka', 'name' => ['en' => 'Inside Dhaka', 'bn' => 'ঢাকার ভেতরে'], 'charge_minor' => 6000],
    ]);
    ($this->rule)('social_commerce.send_pay_link', true);

    SocialPage::inTenantOf($this->w->c1)->create([
        'organization_id' => $this->w->c1->id, 'meta_app_id' => $this->metaApp->id, 'page_id' => '111', 'name' => 'BBU Shop', 'token' => 'PAGE_TOKEN_111',
        'can_post' => true, 'scopes' => Pages::SCOPES, 'subscribed_at' => now(), 'auto_reply' => true, 'price_public' => true,
        'order_branch_id' => $this->w->b1->id, 'order_warehouse_id' => $store['id'], 'status' => SocialPage::CONNECTED, 'version' => 1,
    ]);

    // The shop's own sandbox store; Meta and SSLCommerz answer through one fake.
    (new MerchantAccount)->forceFill([
        'organization_id' => $this->w->c1->id, 'gateway' => 'sslcommerz', 'label' => 'BBU store', 'currency_code' => 'BDT',
        'status' => MerchantAccount::ACTIVE, 'mode' => 'sandbox', 'credentials' => ['store_id' => SHOP_STORE, 'store_password' => SHOP_STORE_PASSWORD], 'version' => 1,
    ])->save();
    fakeMeta([
        '/111/messages' => fn () => Http::response(['recipient_id' => 'PSID1', 'message_id' => 'MID_OUT_'.str()->random(6)]),
        '/gwprocess/v4/api.php' => fn (Request $request) => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://sandbox.sslcommerz.com/EasyCheckOut/'.$request->data()['tran_id'], 'sessionkey' => 'SK1']),
        'validationserverAPI.php' => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $payment = Payment::query()->find(substr((string) ($query['val_id'] ?? ''), 4));
            $amount = number_format($payment->amount_minor / 100, 2, '.', '');

            return Http::response(['status' => 'VALID', 'tran_id' => $payment->id, 'val_id' => $query['val_id'], 'amount' => $amount, 'currency' => 'BDT', 'currency_type' => 'BDT', 'currency_amount' => $amount, 'risk_level' => '0', 'store_id' => SHOP_STORE]);
        },
    ]);

    $this->say = function (string $text) {
        $body = (string) json_encode(['object' => 'page', 'entry' => [['id' => '111', 'time' => 1, 'messaging' => [[
            'sender' => ['id' => 'PSID1'], 'recipient' => ['id' => '111'], 'timestamp' => 1, 'message' => ['mid' => 'MID_'.str()->random(10), 'text' => $text],
        ]]]]]);
        $this->call('POST', "/api/meta/{$this->metaApp->id}/webhook", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, META_SECRET),
        ], $body)->assertOk();

        return ($this->lastSent)();
    };
    $this->lastSent = function () {
        $last = Http::recorded(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/111/messages'))->values()->last();

        return $last === null ? null : json_decode($last[0]['message'], true)['text'];
    };
    // A whole Messenger order; returns the bot's last answer.
    $this->orderInMessenger = function () {
        ($this->say)('soap order korbo');
        foreach (['2', 'Karim Uddin', '01712345678', 'House 4, Road 2, Dhanmondi', '1'] as $answer) {
            ($this->say)($answer);
        }

        return ($this->say)('হ্যাঁ');
    };
    $this->order = fn () => Order::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->sole();
    $this->linkIn = fn (string $text) => preg_match('#https?://\S+#', $text, $found) === 1 ? $found[0] : null;
});

it('sends a payment link for the delivery charge after a Messenger order, and keeps the money when the bank says so', function () {
    $placed = ($this->orderInMessenger)();
    $order = ($this->order)();
    $link = ($this->linkIn)($placed);
    expect($placed)->toContain($order->number)->toContain('৬০.০০৳')->toContain('৪৮ ঘণ্টা')
        ->and($link)->toContain("/pay/orders/{$this->w->c1->id}/{$order->id}")->toContain('signature=')->toContain('lang=bn');

    // The page: no sign-in, no scripts, the order's own amounts.
    $page = $this->get($link)->assertOk()->assertSee('৬০.০০৳')->assertSee($order->number)->assertDontSee('<script', false);
    expect($page->headers->get('Content-Security-Policy'))->toContain("script-src 'none'")
        ->and($page->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');

    // "Pay" goes to the shop's own gateway page; a second click gives the same payment.
    $this->post($link)->assertRedirectContains('https://sandbox.sslcommerz.com/EasyCheckOut/');
    $this->post($link)->assertRedirectContains('https://sandbox.sslcommerz.com/EasyCheckOut/');
    $payment = Payment::query()->where('subject_type', 'online_orders.order')->sole();
    expect($payment)->amount_minor->toBe(6000)->user_id->toBeNull()->subject_id->toBe($order->id)
        ->and($payment->merchantAccount->label)->toBe('BBU store');

    // The bank confirms (twice: kept once); the customer is thanked.
    $this->post('/payments/sslcommerz/notify', sslNotice($payment, password: SHOP_STORE_PASSWORD))->assertOk();
    $this->post('/payments/sslcommerz/notify', sslNotice($payment, password: SHOP_STORE_PASSWORD))->assertOk();
    $money = OrderPayment::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->sole();
    expect(($this->order)()->paid_minor)->toBe(6000)
        ->and($money)->method->toBe('online')->amount_minor->toBe(6000)->recorded_by->toBeNull()
        ->and(($this->lastSent)())->toContain('ধন্যবাদ')->toContain('৬০.০০৳')->toContain($order->number)
        ->and(AuditLog::where('action', 'online_orders.payment_recorded')->count())->toBe(1);

    // Coming back from the bank: the page says it was received; nothing more to pay online.
    $back = $this->post('/payments/sslcommerz/return/success', sslNotice($payment, password: SHOP_STORE_PASSWORD))->assertRedirect();
    $this->get($back->headers->get('Location'))->assertOk()->assertSee('পেমেন্ট পাওয়া গেছে');
    $this->get($link)->assertOk()->assertSee('অনলাইনে দেওয়ার মতো কিছু নেই');
    $this->post($link)->assertUnprocessable();
});

it('asks for what the rules say, and sends no link when they say nothing', function () {
    ($this->rule)('online_orders.online_payment', 'full', $this->w->b1);
    expect(($this->orderInMessenger)())->toContain('২৯০.০০৳');

    // A new conversation: the rule "nothing" means no link at all.
    Order::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->delete();
    ($this->rule)('online_orders.online_payment', 'none', $this->w->b1);
    $this->travel(25)->hours();
    $placed = ($this->orderInMessenger)();
    expect(($this->linkIn)((string) $placed))->toBeNull();
});

it('sends no link unless the company turned it on and can take payments', function () {
    ($this->rule)('social_commerce.send_pay_link', false);
    expect(($this->linkIn)((string) ($this->orderInMessenger)()))->toBeNull();

    // On, but no merchant account: the staff button says why.
    MerchantAccount::query()->delete();
    $this->asToken($this->desk)->postJson("/api/organizations/{$this->w->c1->id}/online-orders/orders/".($this->order)()->id.'/pay-link')
        ->assertStatus(409)->assertJsonPath('code', 'no_pay_link');
});

it('never opens a changed, expired or someone else\'s link', function () {
    $link = ($this->linkIn)(($this->orderInMessenger)());
    $order = ($this->order)();

    // Another order id in the same signed address: the signature no longer holds.
    $this->get(str_replace($order->id, (string) str()->ulid(), $link))->assertStatus(410)->assertSee('মেয়াদ শেষ');
    $this->post(str_replace('lang=bn', 'lang=en', $link))->assertStatus(410);
    expect(Payment::query()->count())->toBe(0);

    // Past pay_link_hours (48).
    $this->travel(49)->hours();
    $this->get($link)->assertStatus(410);
});

it('keeps money the bank confirms even if the order was called off meanwhile', function () {
    $link = ($this->linkIn)(($this->orderInMessenger)());
    $this->post($link)->assertRedirect();
    $payment = Payment::query()->sole();
    $order = ($this->order)();
    $this->asToken($this->desk)->postJson("/api/organizations/{$this->w->b1->id}/online-orders/orders/{$order->id}/cancel", ['base_version' => $order->version, 'reason' => 'Customer changed their mind'])->assertOk();

    $this->post('/payments/sslcommerz/notify', sslNotice($payment, password: SHOP_STORE_PASSWORD))->assertOk();
    expect(($this->order)())->status->toBe('cancelled')->paid_minor->toBe(6000);
});

it('lets the order desk copy a link, and nobody else', function () {
    ($this->rule)('social_commerce.send_pay_link', false);
    ($this->orderInMessenger)();
    $url = fn ($unit) => "/api/organizations/{$unit->id}/online-orders/orders/".($this->order)()->id.'/pay-link';

    $made = $this->asToken($this->desk)->postJson($url($this->w->c1))->assertOk()->assertJsonPath('data.amount_minor', 6000)->json('data');
    expect($made['url'])->toContain('/pay/orders/')->and(AuditLog::where('action', 'online_orders.pay_link_made')->count())->toBe(1);
    $this->get($made['url'])->assertOk();

    $this->asToken($this->viewer)->postJson($url($this->w->c1))->assertForbidden();
    // Another company's desk: the order is not there for them.
    $this->asToken($this->otherDesk)->postJson($url($this->w->c2))->assertNotFound();
    $this->asToken($this->otherDesk)->postJson($url($this->w->c1))->assertNotFound();

    // Online payments off: no link.
    toggles()->disable($this->w->g1, 'online_payments', 'Test');
    $this->asToken($this->desk)->postJson($url($this->w->c1))->assertStatus(409);
});
