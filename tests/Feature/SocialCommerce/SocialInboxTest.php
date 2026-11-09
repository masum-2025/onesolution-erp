<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleTargets;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\SocialCommerce\Models\Message;
use Modules\SocialCommerce\Models\Post;
use Modules\SocialCommerce\Models\PostProduct;
use Modules\SocialCommerce\Models\ReplyTemplate;
use Modules\SocialCommerce\Models\SocialPage;
use Modules\SocialCommerce\Models\Thread;
use Modules\SocialCommerce\Services\Conversations;
use Modules\SocialCommerce\Services\Pages;

/*
 * SC-2: Meta's webhook (checked against the app's signature) brings
 * comments and Messenger messages into one inbox; the bot answers price,
 * stock, delivery and "how to order" with the facts of the moment — the
 * price under the comment or, with the page's switch off, in the inbox with
 * a public note — never twice, never to the page itself; what it does not
 * understand waits for a person (the bell), who answers from the inbox.
 * Isolation between companies and partners; nothing when the module is off.
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
    $this->agentUser = $role(['social_commerce.view', 'social_commerce.inbox'], 'Inbox agent');
    $this->agent = orgToken($this->agentUser, $this->w->c1);
    $this->admin = orgToken($role(['social_commerce.view', 'social_commerce.connect', 'social_commerce.replies'], 'Page admin'), $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/social/{$path}";

    $inv = fn (string $path) => "/api/organizations/{$this->w->c1->id}/inventory/{$path}";
    $pcs = $this->asToken($keeper)->postJson($inv('units'), ['code' => 'PCS', 'name' => ['en' => 'Pieces'], 'decimals' => 0])->assertCreated()->json('data');
    $this->soap = $this->asToken($keeper)->postJson($inv('items'), ['unit_id' => $pcs['id'], 'kind' => 'non_stock', 'sku' => 'SOAP', 'name' => ['en' => 'Soap', 'bn' => 'সাবান'], 'sale_price_minor' => 11500])->assertCreated()->json('data');
    ruleService()->set(app(RuleTargets::class)->organization($this->w->c1->fresh()), 'online_orders.delivery_zones', RuleMode::Set, [
        ['code' => 'dhaka', 'name' => ['en' => 'Inside Dhaka', 'bn' => 'ঢাকার ভেতরে'], 'charge_minor' => 6000],
    ], 'Test setup', trusted: true);

    // A connected, subscribed page with a published post showing the soap.
    $this->page = SocialPage::inTenantOf($this->w->c1)->create([
        'organization_id' => $this->w->c1->id, 'meta_app_id' => $this->metaApp->id, 'page_id' => '111', 'name' => 'BBU Shop', 'token' => 'PAGE_TOKEN_111',
        'meta_person_id' => 'PERSON1', 'can_post' => true, 'scopes' => Pages::SCOPES, 'subscribed_at' => now(), 'auto_reply' => true, 'price_public' => true,
        'status' => SocialPage::CONNECTED, 'version' => 1,
    ]);
    $post = Post::inTenantOf($this->w->c1)->create(['organization_id' => $this->w->c1->id, 'page_id' => $this->page->id, 'message' => 'Soap!', 'locale' => 'bn', 'status' => 'published', 'version' => 1]);
    $post->forceFill(['fb_post_id' => '111_999', 'published_at' => now()])->save();
    PostProduct::inTenantOf($this->w->c1)->create(['organization_id' => $this->w->c1->id, 'post_id' => $post->id, 'item_id' => $this->soap['id'], 'price_minor' => 11500, 'currency_code' => 'BDT']);
    $this->post = $post;

    fakeMeta([
        '/comments' => fn () => Http::response(['id' => 'REPLY_'.str()->random(4)]),
        '/111/messages' => fn () => Http::response(['recipient_id' => 'PSID1', 'message_id' => 'MID_OUT_'.str()->random(4)]),
    ]);

    // Meta delivering one webhook body, signed with the app's secret.
    $this->hook = function (array $payload, ?string $secret = null) {
        $body = (string) json_encode(['object' => 'page', ...$payload]);

        return $this->call('POST', "/api/meta/{$this->metaApp->id}/webhook", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret ?? META_SECRET),
        ], $body);
    };
    $this->comment = fn (string $text, string $id = 'COMMENT1', string $from = 'FAN1', string $page = '111') => ($this->hook)(['entry' => [[
        'id' => $page, 'time' => 1, 'changes' => [['field' => 'feed', 'value' => [
            'item' => 'comment', 'verb' => 'add', 'comment_id' => $id, 'post_id' => '111_999', 'from' => ['id' => $from, 'name' => 'Rahim'], 'message' => $text,
        ]]],
    ]]]);
    $this->message = fn (string $text, string $mid = 'MID1', array $extra = []) => ($this->hook)(['entry' => [[
        'id' => '111', 'time' => 1, 'messaging' => [['sender' => ['id' => 'PSID1'], 'recipient' => ['id' => '111'], 'timestamp' => 1, 'message' => ['mid' => $mid, 'text' => $text, ...$extra]]],
    ]]]);
    $this->sent = fn (string $end) => Http::recorded(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), $end))->values();
    $this->threads = fn () => Thread::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->get();
});

it('answers Meta\'s webhook check only with the app\'s verify token, and takes events only with its signature', function () {
    $this->metaApp->forceFill(['webhook_verify_token' => 'VERIFY_ME'])->save();
    $this->get("/api/meta/{$this->metaApp->id}/webhook?hub.mode=subscribe&hub.verify_token=VERIFY_ME&hub.challenge=12345")->assertOk()->assertSee('12345');
    $this->get("/api/meta/{$this->metaApp->id}/webhook?hub.mode=subscribe&hub.verify_token=WRONG&hub.challenge=12345")->assertForbidden();

    ($this->hook)(['entry' => []], 'not-the-secret')->assertForbidden();
    ($this->comment)('dam koto?')->assertOk();
    expect(($this->threads)())->toHaveCount(1);
});

it('answers a price question under the comment, in Bangla for Banglish, with the price of now — once', function () {
    ($this->comment)('dam koto?')->assertOk();
    $replies = ($this->sent)('/COMMENT1/comments');
    expect($replies)->toHaveCount(1)
        ->and($replies[0][0]['message'])->toContain('সাবান')->toContain('১১৫')
        ->and($replies[0][0]['appsecret_proof'])->toBe(hash_hmac('sha256', 'PAGE_TOKEN_111', META_SECRET));

    $thread = ($this->threads)()->first();
    $messages = Message::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->where('thread_id', $thread->id)->orderBy('created_at')->get();
    expect($thread)->kind->toBe('comment')->customer_name->toBe('Rahim')->status->toBe('open')
        ->and($messages->pluck('sender')->all())->toBe(['customer', 'bot'])
        ->and($messages[0]->intent)->toBe('price')->and($messages[1]->status)->toBe('sent');

    // Meta repeats the event; the customer asks the same again at once: no second answer.
    ($this->comment)('dam koto?')->assertOk();
    ($this->comment)('pp', 'COMMENT2')->assertOk();
    expect(($this->sent)('/comments'))->toHaveCount(1);

    // Another question is answered: delivery from Online orders' zones.
    ($this->comment)('delivery charge koto?', 'COMMENT3')->assertOk();
    expect(($this->sent)('/COMMENT3/comments')[0][0]['message'])->toContain('ঢাকার ভেতরে')->toContain('৬০');
});

it('sends the price to the inbox with a public note when the page\'s price switch is off', function () {
    $this->asToken($this->agent)->patchJson(($this->api)("pages/{$this->page->id}"), ['base_version' => 1, 'price_public' => false])->assertForbidden();
    $this->asToken($this->admin)->patchJson(($this->api)("pages/{$this->page->id}"), ['base_version' => 1, 'price_public' => false])->assertOk()
        ->assertJsonPath('data.price_public', false)->assertJsonPath('data.hears', true);
    expect(AuditLog::where('action', 'social_commerce.page_switches_changed')->count())->toBe(1);

    ($this->comment)('price?')->assertOk();
    $private = ($this->sent)('/111/messages');
    expect($private)->toHaveCount(1)
        ->and(json_decode($private[0][0]['recipient'], true))->toBe(['comment_id' => 'COMMENT1'])
        ->and(json_decode($private[0][0]['message'], true)['text'])->toContain('Soap')
        ->and(($this->sent)('/COMMENT1/comments')[0][0]['message'])->toBe('We sent the price to your inbox ✅');
});

it('answers in Messenger about a product named in the message, and ignores the page\'s own echoes and comments', function () {
    ($this->message)('What is the price of soap?')->assertOk();
    $sent = ($this->sent)('/111/messages');
    expect($sent)->toHaveCount(1)
        ->and(json_decode($sent[0][0]['recipient'], true))->toBe(['id' => 'PSID1'])
        ->and($sent[0][0]['messaging_type'])->toBe('RESPONSE')
        ->and(json_decode($sent[0][0]['message'], true)['text'])->toContain('Soap')->toContain('115');

    // Later, "in stock?" with no product named: the last product talked about.
    $this->travel(11)->minutes();
    ($this->message)('is it in stock?', 'MID2')->assertOk();
    expect(json_decode(($this->sent)('/111/messages')[1][0]['message'], true)['text'])->toContain('Soap')->toContain('In stock');

    ($this->message)('echo', 'MID3', ['is_echo' => true])->assertOk();
    ($this->comment)('dam koto?', 'COMMENT9', '111')->assertOk();
    expect(($this->sent)('/111/messages'))->toHaveCount(2)->and(($this->sent)('/comments'))->toHaveCount(0)
        ->and(($this->threads)())->toHaveCount(1);
});

it('hands what it does not understand to a person once, shows it in the bell, and lets the team answer', function () {
    ($this->message)('hello, are you open on Friday?')->assertOk();
    ($this->message)('hello again?', 'MID2')->assertOk();
    $sent = ($this->sent)('/111/messages');
    expect($sent)->toHaveCount(1)->and(json_decode($sent[0][0]['message'], true)['text'])->toBe('Thank you! One of us will answer you soon.');

    $thread = ($this->threads)()->first();
    expect($thread->status)->toBe('waiting')
        ->and(app(Conversations::class)->waiting($this->w->c1))->toBe(1);
    $this->asToken($this->agent)->getJson('/api/attention')->assertOk()->assertJsonFragment(['key' => 'social_commerce.waiting', 'count' => 1]);

    $list = $this->asToken($this->agent)->getJson(($this->api)('inbox').'?status=waiting')->assertOk();
    expect($list->json('data.0'))->toMatchArray(['id' => $thread->id, 'kind' => 'message', 'status' => 'waiting'])
        ->and($list->json('meta.waiting'))->toBe(1)
        ->and($list->getContent())->not->toContain('PSID1');

    $answered = $this->asToken($this->agent)->postJson(($this->api)("inbox/{$thread->id}/reply"), ['text' => 'Yes, 10am to 8pm.', 'op_id' => 'op-1'])->assertCreated();
    expect($answered->json('data.status'))->toBe('open')
        ->and(collect($answered->json('data.messages'))->last())->toMatchArray(['sender' => 'person', 'text' => 'Yes, 10am to 8pm.', 'status' => 'sent'])
        ->and(AuditLog::where('action', 'social_commerce.reply_sent')->count())->toBe(1);
    // Sent twice with the same op id: once.
    $this->asToken($this->agent)->postJson(($this->api)("inbox/{$thread->id}/reply"), ['text' => 'Yes, 10am to 8pm.', 'op_id' => 'op-1'])->assertCreated();
    expect(($this->sent)('/111/messages'))->toHaveCount(2);

    $done = $this->asToken($this->agent)->postJson(($this->api)("inbox/{$thread->id}/done"), ['base_version' => $answered->json('data.version')])->assertOk();
    expect($done->json('data.status'))->toBe('done');
});

it('stays quiet when the page\'s automatic replies are off: the conversation waits for a person', function () {
    $this->asToken($this->admin)->patchJson(($this->api)("pages/{$this->page->id}"), ['base_version' => 1, 'auto_reply' => false])->assertOk();
    ($this->comment)('dam koto?')->assertOk();
    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), '/comments') || str_contains($request->url(), '/messages')))->toHaveCount(0)
        ->and(($this->threads)()->first()->status)->toBe('waiting');
});

it('lets a company change what the bot understands and try it', function () {
    $this->asToken($this->agent)->getJson(($this->api)('replies'))->assertForbidden();
    $templates = $this->asToken($this->admin)->getJson(($this->api)('replies'))->assertOk()->json('data.templates');
    expect(collect($templates)->pluck('intent')->all())->toBe(ReplyTemplate::INTENTS)
        ->and(array_slice(ReplyTemplate::INTENTS, 0, 4))->toBe(['price', 'stock', 'delivery', 'order']);

    $tried = $this->asToken($this->admin)->postJson(($this->api)('replies/try'), ['text' => 'pp', 'post_id' => $this->post->id])->assertOk();
    expect($tried->json('data'))->toMatchArray(['intent' => 'price', 'locale' => 'bn'])->and($tried->json('data.answer'))->toContain('সাবান');
    $this->asToken($this->admin)->postJson(($this->api)('replies/try'), ['text' => 'koyta lagbe'])->assertOk()->assertJsonPath('data.intent', null)
        ->assertJsonPath('data.handoff', 'Thank you! One of us will answer you soon.');

    $order = collect($templates)->firstWhere('intent', 'order');
    $this->asToken($this->admin)->putJson(($this->api)('replies/order'), [
        'base_version' => $order['version'], 'active' => true,
        'keywords' => ['bn' => ['koyta lagbe', 'নিতে চাই'], 'en' => ['order']], 'answers' => ['bn' => 'ইনবক্সে নাম-ঠিকানা দিন', 'en' => 'Send your name and address'],
    ])->assertOk();
    $this->asToken($this->admin)->postJson(($this->api)('replies/try'), ['text' => 'koyta lagbe'])->assertJsonPath('data.intent', 'order')->assertJsonPath('data.answer', 'ইনবক্সে নাম-ঠিকানা দিন');
    $this->asToken($this->admin)->putJson(($this->api)('replies/order'), ['base_version' => 1, 'active' => true, 'keywords' => [], 'answers' => ['xx' => 'no']])->assertUnprocessable();
    expect(AuditLog::where('action', 'social_commerce.replies_changed')->count())->toBe(1);
});

it('keeps conversations to their company and partner, and does nothing when the module is off', function () {
    ($this->comment)('dam koto?')->assertOk();
    $thread = ($this->threads)()->first();
    $c2 = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['social_commerce.inbox'], 'C2 agent')), $this->w->c2);
    $c4 = orgToken(staffWithRoles($this->w->c4, makeRole($this->w->c4, ['social_commerce.inbox'], 'C4 agent')), $this->w->c4);
    foreach ([$c2, $c4] as $token) {
        $this->asToken($token)->getJson(($this->api)('inbox'))->assertNotFound();
    }
    $this->asToken($c2)->getJson(($this->api)("inbox/{$thread->id}", $this->w->c2))->assertNotFound()->assertJsonPath('code', 'thread_not_found');
    expect($this->asToken($c4)->getJson(($this->api)('inbox', $this->w->c4))->json('data'))->toBe([]);

    // A page nobody here connected: nothing.
    ($this->comment)('dam koto?', 'COMMENT7', 'FAN2', '999')->assertOk();
    expect(($this->threads)())->toHaveCount(1);

    toggles()->disable($this->w->g1, 'social_commerce', 'Stop Facebook selling');
    ($this->comment)('price?', 'COMMENT8', 'FAN3')->assertOk();
    expect(($this->threads)())->toHaveCount(1);
    $this->asToken($this->agent)->getJson(($this->api)('inbox'))->assertForbidden();
});

it('forgets old conversations after the days kept', function () {
    ($this->comment)('dam koto?')->assertOk();
    $this->travel(366)->days();
    expect(app(Conversations::class)->prune())->toBe(1)
        ->and(($this->threads)())->toHaveCount(0)
        ->and(Message::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->count())->toBe(0);
});
