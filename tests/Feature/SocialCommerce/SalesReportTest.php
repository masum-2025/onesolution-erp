<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\OnlineOrders\Models\Order;
use Modules\OnlineOrders\Models\OrderLine;
use Modules\OnlineOrders\Models\OrderPayment;
use Modules\SocialCommerce\Models\Message;
use Modules\SocialCommerce\Models\SocialPage;
use Modules\SocialCommerce\Models\Thread;
use Modules\SocialCommerce\Services\Pages;

/*
 * SC-5c: Facebook sales between two of the company's days (Asia/Dhaka
 * here): conversations, who answered, the Messenger orders (from Online
 * orders' public service) by day, page and product. Read only, with
 * social_commerce.reports at the company; each company sees its own only.
 */

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        setPlan($group, 'business');
        foreach (['inventory', 'social_commerce', 'online_orders'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $metaApp = activeMetaApp($this->w->partnerA);
    $this->boss = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['social_commerce.reports'], 'Owner')), $this->w->c1);
    $this->api = fn (string $query = '', $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id.'/social/reports/sales'.($query === '' ? '' : "?{$query}");

    $page = fn (string $fbId, string $name) => SocialPage::inTenantOf($this->w->c1)->create([
        'organization_id' => $this->w->c1->id, 'meta_app_id' => $metaApp->id, 'page_id' => $fbId, 'name' => $name, 'token' => "TOKEN_{$fbId}",
        'can_post' => true, 'scopes' => Pages::SCOPES, 'subscribed_at' => now(), 'auto_reply' => true, 'price_public' => true, 'status' => SocialPage::CONNECTED, 'version' => 1,
    ]);
    $this->shop = $page('111', 'BBU Shop');
    $this->outlet = $page('222', 'BBU Outlet');

    // A conversation started at a moment (UTC), with its messages: [direction, sender, understood_by].
    $this->thread = function (SocialPage $page, string $kind, string $at, array $messages) {
        $this->travelTo(CarbonImmutable::parse($at, 'UTC'));
        $thread = Thread::inTenantOf($this->w->c1)->create([
            'organization_id' => $this->w->c1->id, 'page_id' => $page->id, 'kind' => $kind, 'thread_key' => 'm:'.Str::random(8),
            'customer_ref' => 'PSID_'.Str::random(6), 'customer_name' => 'Rahim', 'status' => Thread::OPEN, 'version' => 1,
        ]);
        foreach ($messages as [$direction, $sender, $by]) {
            Message::inTenantOf($this->w->c1)->create([
                'organization_id' => $this->w->c1->id, 'thread_id' => $thread->id, 'direction' => $direction, 'sender' => $sender,
                'channel' => Message::PRIVATE, 'text' => 'Hello', 'understood_by' => $by,
            ]);
        }

        return $thread;
    };
    // An order as Online orders keeps it (channel, thread, status, total, a line, online payment).
    $this->order = function (string $at, string $channel, ?string $threadId, string $status, int $total, int $paidOnline = 0, string $currency = 'BDT') {
        $this->travelTo(CarbonImmutable::parse($at, 'UTC'));
        $order = (new Order)->forceFill([
            'organization_id' => $this->w->c1->id, 'unit_id' => $this->w->b1->id, 'warehouse_id' => (string) Str::ulid(), 'number' => 'WO-'.Str::random(5),
            'channel' => $channel, 'channel_ref' => $threadId, 'status' => $status, 'customer_name' => 'Rahim', 'customer_phone' => '+8801711000000',
            'address' => 'Dhaka', 'payment_method' => 'cod', 'subtotal_minor' => $total - 6000, 'delivery_minor' => 6000, 'total_minor' => $total,
            'paid_minor' => $paidOnline, 'currency_code' => $currency, 'prices_include_tax' => true, 'created_by' => $this->w->c1->id, 'op_id' => (string) Str::ulid(), 'version' => 1,
        ]);
        $order->save();
        (new OrderLine)->forceFill([
            'organization_id' => $this->w->c1->id, 'order_id' => $order->id, 'line_no' => 1, 'item_id' => $this->soapId, 'sku' => 'SOAP', 'name' => ['en' => 'Soap', 'bn' => 'সাবান'],
            'keeps_stock' => true, 'quantity_milli' => intdiv($total - 6000, 20000) * 1000, 'unit_price_minor' => 20000, 'discount_minor' => 0, 'tax_rate_bp' => 0,
            'tax_minor' => 0, 'net_minor' => $total - 6000, 'total_minor' => $total - 6000, 'cost_minor' => 0,
        ])->save();
        if ($paidOnline > 0) {
            (new OrderPayment)->forceFill(['organization_id' => $this->w->c1->id, 'order_id' => $order->id, 'kind' => 'payment', 'method' => OrderPayment::ONLINE,
                'amount_minor' => $paidOnline, 'reference' => 'TX', 'op_id' => (string) Str::ulid(), 'created_at' => now()])->save();
        }

        return $order;
    };
    $this->soapId = (string) Str::ulid();

    // Before the period: not counted.
    $old = ($this->thread)($this->shop, Thread::MESSAGE, '2026-10-20 06:00', [['in', 'customer', 'keyword']]);
    ($this->order)('2026-10-20 06:05', 'facebook', $old->id, 'delivered', 46000);
    // 5 Nov (Dhaka): a Messenger chat on the shop page, read by keyword and by AI, answered by bot and person; delivered, paid online.
    $chat = ($this->thread)($this->shop, Thread::MESSAGE, '2026-11-05 06:00', [['in', 'customer', 'keyword'], ['out', 'bot', null], ['in', 'customer', 'ai'], ['out', 'person', null]]);
    ($this->order)('2026-11-05 06:30', 'facebook', $chat->id, 'delivered', 46000, 46000);
    // 6 Nov 23:30 Dhaka (17:30 UTC): a comment on the outlet page nobody understood; its order cancelled.
    $comment = ($this->thread)($this->outlet, Thread::COMMENT, '2026-11-06 17:30', [['in', 'customer', null], ['out', 'person', null]]);
    ($this->order)('2026-11-06 17:40', 'facebook', $comment->id, 'cancelled', 26000);
    // 7 Nov: a chat that did not end in an order, a manual order (not Facebook's), and an order in another currency.
    ($this->thread)($this->shop, Thread::MESSAGE, '2026-11-07 05:00', [['in', 'customer', 'keyword'], ['out', 'bot', null]]);
    ($this->order)('2026-11-07 05:10', 'manual', null, 'confirmed', 66000);
    ($this->order)('2026-11-07 05:20', 'facebook', null, 'confirmed', 9000, 0, 'USD');
    $this->travelTo(CarbonImmutable::parse('2026-11-09 04:00', 'UTC'));
});

it('counts conversations, answers, orders, days, pages and products', function () {
    $report = $this->asToken($this->boss)->getJson(($this->api)('from=2026-11-01&to=2026-11-09'))->assertOk()->json('data');

    expect($report['timezone'])->toBe('Asia/Dhaka')->and($report['currency'])->toBe('BDT')->and($report['orders_on'])->toBeTrue()
        ->and($report['conversations'])->toBe(['new' => 3, 'comments' => 1, 'messages' => 2, 'active' => 3, 'with_order' => 2])
        ->and($report['conversion_bp'])->toBe(6666)
        ->and($report['answers'])->toBe(['received' => 4, 'bot' => 2, 'person' => 2, 'by_keyword' => 2, 'by_ai' => 1, 'not_understood' => 1])
        ->and($report['orders'])->toBe(['count' => 3, 'amount' => 72000, 'average' => 36000, 'delivered' => 1, 'delivered_amount' => 46000,
            'cancelled' => 1, 'open' => 1, 'paid_online' => 46000, 'other_currency' => 1]);
    // Days run 1 to 9 Nov; the late comment counts on 6 Nov in Dhaka.
    $days = collect($report['days'])->keyBy('date');
    expect($days)->toHaveCount(9)
        ->and($days['2026-11-05'])->toMatchArray(['conversations' => 1, 'orders' => 1, 'amount' => 46000])
        ->and($days['2026-11-06'])->toMatchArray(['conversations' => 1, 'orders' => 1, 'amount' => 26000])
        ->and($days['2026-11-07'])->toMatchArray(['conversations' => 1, 'orders' => 1, 'amount' => 0]);
    expect(collect($report['pages'])->keyBy('name')->all())->toMatchArray([
        'BBU Shop' => ['page_id' => $this->shop->id, 'name' => 'BBU Shop', 'conversations' => 2, 'orders' => 1, 'amount' => 46000, 'delivered' => 1],
        'BBU Outlet' => ['page_id' => $this->outlet->id, 'name' => 'BBU Outlet', 'conversations' => 1, 'orders' => 1, 'amount' => 26000, 'delivered' => 0],
    ]);
    // Cancelled and foreign-currency orders are left out of the products.
    expect($report['items'])->toHaveCount(1)
        ->and($report['items'][0])->toMatchArray(['item_id' => $this->soapId, 'quantity_milli' => 2000, 'amount' => 40000, 'orders' => 1]);
});

it('narrows to a page and to the days asked, the last 30 days by default', function () {
    $outlet = $this->asToken($this->boss)->getJson(($this->api)("from=2026-11-01&to=2026-11-09&page_id={$this->outlet->id}"))->assertOk()->json('data');
    expect($outlet['conversations']['active'])->toBe(1)->and($outlet['orders']['count'])->toBe(1)->and($outlet['orders']['amount'])->toBe(26000)
        ->and($outlet['answers']['received'])->toBe(1)->and($outlet['pages'])->toHaveCount(1);

    $fifth = $this->asToken($this->boss)->getJson(($this->api)('from=2026-11-05&to=2026-11-05'))->assertOk()->json('data');
    expect($fifth['orders']['count'])->toBe(1)->and($fifth['conversations']['new'])->toBe(1)->and($fifth['days'])->toHaveCount(1);

    $default = $this->asToken($this->boss)->getJson(($this->api)())->assertOk()->json('data');
    expect($default['from'])->toBe('2026-10-11')->and($default['to'])->toBe('2026-11-09')->and($default['orders']['count'])->toBe(4);
});

it('turns down bad periods and unknown pages', function () {
    $this->asToken($this->boss)->getJson(($this->api)('from=2026-11-09&to=2026-11-01'))->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->asToken($this->boss)->getJson(($this->api)('from=2025-01-01&to=2026-11-09'))->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->asToken($this->boss)->getJson(($this->api)('from=9-11-2026'))->assertUnprocessable()->assertJsonValidationErrors('from');
    $this->asToken($this->boss)->getJson(($this->api)('page_id='.Str::ulid()))->assertNotFound();
});

it('needs the reports permission and the modules on; without Online orders only conversations show', function () {
    $inbox = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['social_commerce.inbox', 'social_commerce.view'], 'Agent')), $this->w->c1);
    $this->asToken($inbox)->getJson(($this->api)())->assertForbidden();

    toggles()->disable($this->w->g1, 'online_orders', 'Test', confirm: true);
    $report = $this->asToken($this->boss)->getJson(($this->api)('from=2026-11-01&to=2026-11-09'))->assertOk()->json('data');
    expect($report['orders_on'])->toBeFalse()->and($report['orders']['count'])->toBe(0)->and($report['conversations']['active'])->toBe(3);

    toggles()->disable($this->w->g1, 'social_commerce', 'Test', confirm: true);
    $this->asToken($this->boss)->getJson(($this->api)())->assertForbidden();
});

it('shows each company its own only', function () {
    $c2 = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['social_commerce.reports'], 'C2 owner')), $this->w->c2);
    $c4 = orgToken(staffWithRoles($this->w->c4, makeRole($this->w->c4, ['social_commerce.reports'], 'C4 owner')), $this->w->c4);
    foreach ([$c2, $c4] as $token) {
        $this->asToken($token)->getJson(($this->api)())->assertNotFound();
    }
    $own = $this->asToken($c2)->getJson(($this->api)('from=2026-11-01&to=2026-11-09', $this->w->c2))->assertOk()->json('data');
    expect($own['conversations']['active'])->toBe(0)->and($own['orders']['count'])->toBe(0)->and($own['pages'])->toBe([])->and($own['items'])->toBe([]);
    $this->asToken($c2)->getJson(($this->api)("page_id={$this->shop->id}", $this->w->c2))->assertNotFound();
});
