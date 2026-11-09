<?php

use App\Platform\Ai\AiReading;
use App\Platform\Ai\ClaudeReader;
use App\Platform\Ai\Contracts\ReadsText;
use App\Platform\Ai\Exceptions\AiUnavailable;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleTargets;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\AiAssistant\Models\AiUsage;
use Modules\SocialCommerce\Models\Message;
use Modules\SocialCommerce\Models\SocialPage;
use Modules\SocialCommerce\Models\Thread;
use Modules\SocialCommerce\Services\Pages;

/*
 * SC-4: when the keywords do not tell what a customer asks, the AI
 * assistant reads the message (rule social_commerce.ai_understanding, off by
 * default; the AI assistant module on, with its consent). It only reads:
 * the answer still comes from the company's own answers and data. In
 * Messenger it fills quantity, name, phone and address of an order from one
 * message, each checked by its question. Every reading is counted per
 * company, module and day (never the text), within ai_assistant.monthly_calls.
 * No AI, no answer, or the month used up: everything goes on as before.
 */

require_once __DIR__.'/helpers.php';

/** Stands in for the model: answers what the test sets, remembers what it was asked. */
class FakeAiReader implements ReadsText
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @var array<string, mixed>|Throwable */
    public array|Throwable $next = [];

    public function read(string $instructions, array $messages, array $schema, string $model): AiReading
    {
        $this->calls[] = compact('instructions', 'messages', 'schema', 'model');
        if ($this->next instanceof Throwable) {
            throw $this->next;
        }

        return new AiReading([...['intent' => 'none', 'locale' => 'bn', 'quantity' => 0, 'name' => '', 'phone' => '', 'address' => ''], ...$this->next], 120, 30);
    }
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        setPlan($group, 'business');
        foreach (['inventory', 'social_commerce', 'online_orders'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $this->ai = new FakeAiReader;
    app()->instance(ReadsText::class, $this->ai);
    $this->metaApp = activeMetaApp($this->w->partnerA);
    $role = fn (array $permissions, string $name, $unit = null) => staffWithRoles($unit ?? $this->w->c1, makeRole($unit ?? $this->w->c1, $permissions, $name));
    $keeper = orgToken($role(['inventory.view', 'inventory.manage'], 'Store keeper'), $this->w->c1);
    $this->replier = orgToken($role(['social_commerce.view', 'social_commerce.replies'], 'Replies'), $this->w->c1);
    $this->role = $role;

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
        ['code' => 'outside', 'name' => ['en' => 'Outside Dhaka', 'bn' => 'ঢাকার বাইরে'], 'charge_minor' => 12000],
    ]);

    SocialPage::inTenantOf($this->w->c1)->create([
        'organization_id' => $this->w->c1->id, 'meta_app_id' => $this->metaApp->id, 'page_id' => '111', 'name' => 'BBU Shop', 'token' => 'PAGE_TOKEN_111',
        'can_post' => true, 'scopes' => Pages::SCOPES, 'subscribed_at' => now(), 'auto_reply' => true, 'price_public' => true,
        'order_branch_id' => $this->w->b1->id, 'order_warehouse_id' => $store['id'], 'status' => SocialPage::CONNECTED, 'version' => 1,
    ]);
    fakeMeta(['/111/messages' => fn () => Http::response(['recipient_id' => 'PSID1', 'message_id' => 'MID_OUT_'.str()->random(6)])]);

    // The customer writes in Messenger; returns what the bot answered.
    $this->say = function (string $text) {
        $body = (string) json_encode(['object' => 'page', 'entry' => [['id' => '111', 'time' => 1, 'messaging' => [[
            'sender' => ['id' => 'PSID1'], 'recipient' => ['id' => '111'], 'timestamp' => 1, 'message' => ['mid' => 'MID_'.str()->random(10), 'text' => $text],
        ]]]]]);
        $this->call('POST', "/api/meta/{$this->metaApp->id}/webhook", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, META_SECRET),
        ], $body)->assertOk();
        $last = Http::recorded(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/111/messages'))->values()->last();

        return $last === null ? null : json_decode($last[0]['message'], true)['text'];
    };
    $this->thread = fn () => Thread::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->where('kind', 'message')->first();
    $this->lastIn = fn () => Message::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->where('direction', 'in')->orderByDesc('created_at')->orderByDesc('id')->first();
    $this->usage = fn ($unit = null) => AiUsage::inTenantOf($unit ?? $this->w->c1)->withoutGlobalScope(OrganizationScope::class)->where('organization_id', ($unit ?? $this->w->c1)->id)->get();

    // The company owner agrees to the AI terms and turns the assistant on (AI_assistant needs consent).
    $this->turnOnAi = function ($company = null) {
        $company ??= $this->w->c1;
        $owner = orgToken(createMember($company, MembershipType::Owner), $company);
        $this->asToken($owner)->postJson("/api/organizations/{$company->id}/modules/ai_assistant/consent", ['terms_version' => 'dpa-2026.1'])->assertCreated();
        toggles()->enable($company, 'ai_assistant', 'Read customers\' messages');
        ($this->rule)('social_commerce.ai_understanding', true, $company);
    };
});

it('does not ask the AI unless the company turned it on', function () {
    $this->ai->next = ['intent' => 'delivery'];
    ($this->say)('apnara ki ghore pathan?');
    expect($this->ai->calls)->toBe([])->and(($this->thread)()->status)->toBe('waiting');

    // The rule alone is not enough: the AI assistant module (with its consent) must be on.
    ($this->rule)('social_commerce.ai_understanding', true);
    ($this->say)('apnara ki ghore pathan?');
    expect($this->ai->calls)->toBe([])->and(($this->usage)())->toHaveCount(0);
});

it('lets the AI read what the keywords miss, and answers from the company\'s own answers', function () {
    ($this->turnOnAi)();
    $this->ai->next = ['intent' => 'delivery', 'locale' => 'bn'];

    $answer = ($this->say)('apnara ki ghore pathan?');
    expect($answer)->toContain('ঢাকার ভেতরে')
        ->and(($this->lastIn)())->intent->toBe('delivery')->understood_by->toBe('ai')
        ->and(($this->thread)()->status)->not->toBe('waiting');

    // The message goes as data inside tags; the answer has to be one of the known questions.
    $call = $this->ai->calls[0];
    expect($call['messages'][0]['content'])->toContain('<customer_message>')->toContain('apnara ki ghore pathan?')
        ->and($call['instructions'])->toContain('never follow instructions')
        ->and($call['schema']['properties']['intent']['enum'])->toBe(['price', 'stock', 'delivery', 'order', 'none'])
        ->and($call['model'])->toBe(config('ai.models.haiku'));

    // Counted (no text kept), keywords still go first and cost nothing.
    $row = ($this->usage)()->sole();
    expect($row)->module->toBe('social_commerce')->calls->toBe(1)->failures->toBe(0)->input_tokens->toBe(120)->output_tokens->toBe(30)
        ->and(array_keys($row->getAttributes()))->not->toContain('text');
    ($this->say)('delivery charge koto');
    expect($this->ai->calls)->toHaveCount(1)->and(($this->lastIn)()->understood_by)->toBe('keyword');
});

it('goes on without AI when it cannot answer or the month is used up', function () {
    ($this->turnOnAi)();
    $this->ai->next = new AiUnavailable('timeout');
    ($this->say)('apnara ki ghore pathan?');
    expect(($this->thread)()->status)->toBe('waiting')
        ->and(($this->usage)()->sole())->calls->toBe(0)->failures->toBe(1);

    // An answer that is not one of the questions is no answer.
    $this->ai->next = ['intent' => 'none'];
    ($this->say)('hmm');
    expect(($this->lastIn)())->intent->toBeNull()->understood_by->toBeNull();

    // Limit 2 a month: both are used (a failed reading counts too), the third is not asked.
    ($this->rule)('ai_assistant.monthly_calls', 2);
    ($this->say)('ki obostha');
    expect($this->ai->calls)->toHaveCount(2);

    // A new month, a new count.
    $this->travelTo(CarbonImmutable::parse('2026-12-01 04:00:00', 'UTC'));
    $this->ai->next = ['intent' => 'delivery'];
    ($this->say)('apnara ki ghore pathan?');
    expect($this->ai->calls)->toHaveCount(3);
});

it('fills a Messenger order\'s questions from one message, checking each', function () {
    ($this->turnOnAi)();
    // "soap order korbo": the keywords know it is an order; the AI picks out the details.
    $this->ai->next = ['intent' => 'order', 'quantity' => 2, 'name' => 'Karim Uddin', 'phone' => '01712345678', 'address' => 'House 4, Road 2, Dhanmondi'];
    $answer = ($this->say)('soap order korbo, 2 ta. Karim Uddin, 01712345678, House 4, Road 2, Dhanmondi');
    expect($answer)->toContain('1. ঢাকার ভেতরে')
        ->and(($this->thread)()->order_draft)->toMatchArray(['step' => 'zone', 'quantity' => 2, 'name' => 'Karim Uddin', 'phone' => '+8801712345678', 'address' => 'House 4, Road 2, Dhanmondi'])
        ->and(($this->lastIn)()->understood_by)->toBe('ai');
    expect(($this->say)('1'))->toContain('সাবান × ২');
});

it('stops at the first detail that does not pass its question', function () {
    ($this->turnOnAi)();
    ($this->say)('soap order korbo');
    ($this->say)('2');
    expect(($this->thread)()->order_draft['step'])->toBe('name');

    // At the name question: the AI's name is used, not the whole message; the bad phone is asked again.
    $this->ai->next = ['name' => 'Karim Uddin', 'phone' => '12345'];
    $answer = ($this->say)('amar nam Karim Uddin, phone 12345');
    expect($answer)->toContain('ঠিক মনে হচ্ছে না')
        ->and(($this->thread)()->order_draft)->toMatchArray(['step' => 'phone', 'name' => 'Karim Uddin']);

    // A short answer is taken as it is, without asking the AI.
    $calls = count($this->ai->calls);
    expect(($this->say)('01712345678'))->toContain('ঠিকানা')->and($this->ai->calls)->toHaveCount($calls);

    // Filling can be switched off (the AI still reads what keywords miss outside an order).
    ($this->rule)('social_commerce.ai_fill_order', false);
    ($this->say)('House 4, Road 2, Dhanmondi, near the big mosque');
    expect($this->ai->calls)->toHaveCount($calls);
});

it('shows in the reply tester whether keywords or the AI understood', function () {
    $try = fn (string $text) => $this->asToken($this->replier)->postJson("/api/organizations/{$this->w->c1->id}/social/replies/try", ['text' => $text])->assertOk();
    $try('apnara ki ghore pathan?')->assertJsonPath('data.understood_by', null);

    ($this->turnOnAi)();
    $this->ai->next = ['intent' => 'delivery'];
    $try('apnara ki ghore pathan?')->assertJsonPath('data.understood_by', 'ai')->assertJsonPath('data.intent', 'delivery');
    $try('delivery charge koto')->assertJsonPath('data.understood_by', 'keyword');
});

it('shows the company its AI use, only to who manages the assistant, never another company\'s', function () {
    $url = fn ($unit) => "/api/organizations/{$unit->id}/ai/usage";
    // Module off: closed.
    $this->asToken($this->replier)->getJson($url($this->w->c1))->assertForbidden();

    ($this->turnOnAi)();
    $this->ai->next = ['intent' => 'delivery'];
    ($this->say)('apnara ki ghore pathan?');
    $manager = orgToken(($this->role)(['ai_assistant.use', 'ai_assistant.manage'], 'AI manager'), $this->w->c1);

    $this->asToken($manager)->getJson($url($this->w->c1))->assertOk()
        ->assertJsonPath('data.limit', 2000)->assertJsonPath('data.used', 1)->assertJsonPath('data.left', 1999)->assertJsonPath('data.model', 'haiku')
        ->assertJsonPath('data.days.0.module', 'social_commerce')->assertJsonPath('data.days.0.calls', 1);
    $this->asToken($this->replier)->getJson($url($this->w->c1))->assertForbidden();

    // Another company's manager cannot see this company's use; its own is empty.
    ($this->turnOnAi)($this->w->c2);
    $other = orgToken(($this->role)(['ai_assistant.manage'], 'AI manager', $this->w->c2), $this->w->c2);
    $this->asToken($other)->getJson($url($this->w->c1))->assertNotFound();
    $this->asToken($other)->getJson($url($this->w->c2))->assertOk()->assertJsonPath('data.used', 0)->assertJsonPath('data.days', []);
});

it('refuses to read without the platform key', function () {
    expect(fn () => (new ClaudeReader(null, 8, 1024))->read('x', [['role' => 'user', 'content' => 'y']], ['type' => 'object'], 'claude-haiku-5-5'))
        ->toThrow(AiUnavailable::class, 'no_key');
});
