<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleTargets;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\OnlineOrders\Models\Order;
use Modules\SocialCommerce\Models\SocialPage;
use Modules\SocialCommerce\Models\Thread;
use Modules\SocialCommerce\Services\Pages;

/*
 * SC-3: a customer orders in Messenger: the bot asks the product (by
 * number), how many (no more than can go out), name, mobile (read by the
 * country), address and area, shows the summary with the real total, and
 * "yes" makes a draft order in Online orders (channel facebook, nobody's
 * typing). "Cancel" or silence drops it. The customer hears how their order
 * moves on (past 24 hours with Meta's order-update tag). Only where the page
 * has a branch and warehouse, the rule is on and Online orders is on.
 */

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        setPlan($group, 'business');
        foreach (['inventory', 'social_commerce', 'online_orders'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $this->metaApp = activeMetaApp($this->w->partnerA);
    $role = fn (array $permissions, string $name, $unit = null) => staffWithRoles($unit ?? $this->w->c1, makeRole($unit ?? $this->w->c1, $permissions, $name));
    $keeper = orgToken($role(['inventory.view', 'inventory.manage'], 'Store keeper'), $this->w->c1);
    $this->admin = orgToken($role(['social_commerce.view', 'social_commerce.connect', 'social_commerce.inbox'], 'Page admin'), $this->w->c1);
    $this->deskUser = $role(['online_orders.view', 'online_orders.manage'], 'Order desk');
    $this->desk = orgToken($this->deskUser, $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/social/{$path}";

    $inv = fn (string $path) => "/api/organizations/{$this->w->c1->id}/inventory/{$path}";
    $pcs = $this->asToken($keeper)->postJson($inv('units'), ['code' => 'PCS', 'name' => ['en' => 'Pieces'], 'decimals' => 0])->assertCreated()->json('data');
    $this->store = $this->asToken($keeper)->postJson($inv('warehouses'), ['code' => 'WEB', 'name' => ['en' => 'Web store'], 'unit_id' => $this->w->c1->id])->assertCreated()->json('data');
    $this->soap = $this->asToken($keeper)->postJson($inv('items'), ['unit_id' => $pcs['id'], 'kind' => 'stock', 'sku' => 'SOAP', 'name' => ['en' => 'Soap', 'bn' => 'সাবান'], 'sale_price_minor' => 11500])->assertCreated()->json('data');
    $receipt = $this->asToken($keeper)->postJson($inv('documents'), ['type' => 'receipt', 'warehouse_id' => $this->store['id'], 'document_date' => '2026-11-01', 'lines' => [
        ['item_id' => $this->soap['id'], 'quantity_milli' => 5000, 'unit_cost_minor' => 6000],
    ]])->assertCreated()->json('data');
    $this->asToken($keeper)->postJson($inv("documents/{$receipt['id']}/post"), ['base_version' => $receipt['version']])->assertOk();
    ruleService()->set(app(RuleTargets::class)->organization($this->w->c1->fresh()), 'online_orders.delivery_zones', RuleMode::Set, [
        ['code' => 'dhaka', 'name' => ['en' => 'Inside Dhaka', 'bn' => 'ঢাকার ভেতরে'], 'charge_minor' => 6000],
        ['code' => 'outside', 'name' => ['en' => 'Outside Dhaka', 'bn' => 'ঢাকার বাইরে'], 'charge_minor' => 12000],
    ], 'Test setup', trusted: true);

    $this->page = SocialPage::inTenantOf($this->w->c1)->create([
        'organization_id' => $this->w->c1->id, 'meta_app_id' => $this->metaApp->id, 'page_id' => '111', 'name' => 'BBU Shop', 'token' => 'PAGE_TOKEN_111',
        'can_post' => true, 'scopes' => Pages::SCOPES, 'subscribed_at' => now(), 'auto_reply' => true, 'price_public' => true,
        'order_branch_id' => $this->w->b1->id, 'order_warehouse_id' => $this->store['id'], 'status' => SocialPage::CONNECTED, 'version' => 1,
    ]);

    fakeMeta([
        '/comments' => fn () => Http::response(['id' => 'REPLY_'.str()->random(4)]),
        '/111/messages' => fn () => Http::response(['recipient_id' => 'PSID1', 'message_id' => 'MID_OUT_'.str()->random(6)]),
    ]);
    $this->hook = function (array $payload) {
        $body = (string) json_encode(['object' => 'page', ...$payload]);

        return $this->call('POST', "/api/meta/{$this->metaApp->id}/webhook", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, META_SECRET),
        ], $body)->assertOk();
    };
    // The customer writes; returns what the bot answered.
    $this->say = function (string $text) {
        ($this->hook)(['entry' => [['id' => '111', 'time' => 1, 'messaging' => [[
            'sender' => ['id' => 'PSID1'], 'recipient' => ['id' => '111'], 'timestamp' => 1, 'message' => ['mid' => 'MID_'.str()->random(10), 'text' => $text],
        ]]]]]);
        $last = Http::recorded(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/111/messages'))->values()->last();

        return $last === null ? null : json_decode($last[0]['message'], true)['text'];
    };
    $this->thread = fn () => Thread::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->where('kind', 'message')->first();
    $this->orders = fn () => Order::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->get();
});

it('takes an order step by step and makes a draft order in Online orders', function () {
    expect(($this->say)('order korbo'))->toContain('কোন পণ্যটি নেবেন')->toContain('1. সাবান');
    expect(($this->say)('1'))->toContain('সাবান কয়টি নেবেন');
    expect(($this->say)('10'))->toContain('৫টি আছে');
    expect(($this->say)('২টা'))->toContain('আপনার নাম');
    expect(($this->say)('Karim Uddin'))->toContain('মোবাইল নম্বর');
    expect(($this->say)('12345'))->toContain('ঠিক মনে হচ্ছে না');
    expect(($this->say)('01712345678'))->toContain('ঠিকানা');
    // "no" inside an address is not "stop".
    expect(($this->say)('House no 4, Road 2, Dhanmondi'))->toContain('1. ঢাকার ভেতরে')->toContain('2. ঢাকার বাইরে');
    $summary = ($this->say)('1');
    expect($summary)->toContain('সাবান × ২')->toContain('ঢাকার ভেতরে')->toContain('২৯০')->toContain('+8801712345678');
    expect(($this->orders)())->toHaveCount(0);

    $placed = ($this->say)('হ্যাঁ');
    $order = ($this->orders)()->first();
    expect($placed)->toContain($order->number)
        ->and($order)->status->toBe('draft')->channel->toBe('facebook')->channel_ref->toBe(($this->thread)()->id)
        ->created_by->toBeNull()->customer_name->toBe('Karim Uddin')->customer_phone->toBe('+8801712345678')->delivery_zone->toBe('dhaka')->total_minor->toBe(29000)
        ->and(($this->thread)())->order_draft->toBeNull()->last_order_id->toBe($order->id)
        ->and(AuditLog::where('action', 'social_commerce.order_taken')->count())->toBe(1);

    // "Yes" again does not make a second order; the inbox shows the order.
    ($this->say)('হ্যাঁ');
    expect(($this->orders)())->toHaveCount(1);
    $this->asToken($this->admin)->getJson(($this->api)('inbox/'.($this->thread)()->id))->assertOk()
        ->assertJsonPath('data.order.number', $order->number)->assertJsonPath('data.draft', null);
});

it('tells the customer how the order moves on, with Meta\'s order-update tag after a day', function () {
    ($this->say)('soap order korbo');
    foreach (['1', 'Karim', '01712345678', 'House 4, Road 2, Dhanmondi', '2', 'ha'] as $answer) {
        ($this->say)($answer);
    }
    $order = ($this->orders)()->first();
    expect($order->delivery_zone)->toBe('outside');
    $step = fn (string $name, array $body = []) => $this->asToken($this->desk)->postJson("/api/organizations/{$this->w->b1->id}/online-orders/orders/{$order->id}/{$name}", ['base_version' => $order->fresh()->version, ...$body])->assertOk();

    $step('confirm');
    $news = Http::recorded(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/111/messages'))->values()->last()[0];
    expect(json_decode($news['message'], true)['text'])->toContain($order->number)->toContain('নিশ্চিত')
        ->and($news['messaging_type'])->toBe('RESPONSE');

    $this->travel(25)->hours();
    // A day later the desk signs in again (tokens do not last a day).
    $this->desk = orgToken($this->deskUser, $this->w->c1);
    $step('ship', ['courier' => 'Pathao', 'tracking' => 'PT123']);
    $news = Http::recorded(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/111/messages'))->values()->last()[0];
    expect(json_decode($news['message'], true)['text'])->toContain('পাঠানো হয়েছে')->toContain('Pathao')->toContain('PT123')
        ->and($news['messaging_type'])->toBe('MESSAGE_TAG')->and($news['tag'])->toBe('POST_PURCHASE_UPDATE');
});

it('drops the order on "cancel" or after the hours allowed', function () {
    ($this->say)('order korbo');
    expect(($this->say)('বাতিল'))->toContain('বাতিল করা হলো')
        ->and(($this->thread)()->order_draft)->toBeNull();

    ($this->say)('soap order korbo');
    expect(($this->thread)()->order_draft['step'])->toBe('quantity');
    $this->travel(25)->hours();
    // Too late: "2" is not an answer any more; a person takes over.
    expect(($this->say)('2'))->toBe('ধন্যবাদ! আমাদের একজন শিগগির উত্তর দেবেন।')
        ->and(($this->thread)())->order_draft->toBeNull()->status->toBe('waiting')
        ->and(($this->orders)())->toHaveCount(0);
});

it('takes no order where the page has no branch and warehouse, the rule is off, or in comments', function () {
    $this->page->forceFill(['order_warehouse_id' => null])->save();
    expect(($this->say)('order korbo'))->toContain('নাম, মোবাইল নম্বর আর পুরো ঠিকানা')
        ->and(($this->thread)()->order_draft)->toBeNull();

    $this->page->forceFill(['order_warehouse_id' => $this->store['id']])->save();
    ruleService()->set(app(RuleTargets::class)->organization($this->w->c1->fresh()), 'social_commerce.messenger_orders', RuleMode::Set, false, 'Test setup', trusted: true);
    $this->travel(11)->minutes();
    ($this->say)('order korbo');
    expect(($this->thread)()->order_draft)->toBeNull();

    ($this->hook)(['entry' => [['id' => '111', 'time' => 1, 'changes' => [['field' => 'feed', 'value' => [
        'item' => 'comment', 'verb' => 'add', 'comment_id' => 'C1', 'post_id' => '111_9', 'from' => ['id' => 'FAN1', 'name' => 'Rahim'], 'message' => 'order korbo',
    ]]]]]]);
    expect(Thread::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->where('kind', 'comment')->first()->order_draft)->toBeNull();
});

it('lets only the company\'s own branches and warehouses take a page\'s orders', function () {
    $this->asToken($this->admin)->getJson(($this->api)('pages'))->assertOk()
        ->assertJsonPath('data.pages.0.takes_orders', true)
        ->assertJsonPath('data.order_places.warehouses.0.id', $this->store['id']);
    $this->asToken($this->admin)->patchJson(($this->api)("pages/{$this->page->id}"), ['base_version' => 1, 'order_branch_id' => $this->w->b2->id])
        ->assertNotFound()->assertJsonPath('code', 'branch_not_found');
    $this->asToken($this->admin)->patchJson(($this->api)("pages/{$this->page->id}"), ['base_version' => 1, 'order_warehouse_id' => (string) str()->ulid()])
        ->assertNotFound()->assertJsonPath('code', 'warehouse_not_found');
    $this->asToken($this->admin)->patchJson(($this->api)("pages/{$this->page->id}"), ['base_version' => 1, 'order_branch_id' => null])->assertOk()
        ->assertJsonPath('data.takes_orders', false);
});
