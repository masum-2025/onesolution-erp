<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleTargets;
use App\Platform\Tenancy\Models\Partner;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\SocialCommerce\Models\Post;
use Modules\SocialCommerce\Models\SocialPage;
use Modules\SocialCommerce\Services\Pages;
use Modules\SocialCommerce\Services\Posts;

/*
 * SC-1: a company connects its Facebook pages through its partner's Meta
 * app (or the house app where allowed) without a token ever reaching the
 * browser; posts with products and photos are written, approved and put
 * on Facebook at their time; Facebook's refusals are handled (sign in
 * again, try later); isolation between companies and partners; off when
 * the module is off. Meta is faked: no call leaves the test.
 */

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-11-09 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        setPlan($group, 'business');
        foreach (['inventory', 'social_commerce'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $this->metaApp = activeMetaApp($this->w->partnerA);
    $role = fn (array $permissions, string $name, $unit = null) => staffWithRoles($unit ?? $this->w->c1, makeRole($unit ?? $this->w->c1, $permissions, $name));
    $this->keeper = orgToken($role(['inventory.view', 'inventory.manage'], 'Store keeper'), $this->w->c1);
    $this->writerUser = $role(['social_commerce.view', 'social_commerce.compose'], 'Post writer');
    $this->writer = orgToken($this->writerUser, $this->w->c1);
    $this->approver = orgToken($role(['social_commerce.view', 'social_commerce.approve'], 'Post approver'), $this->w->c1);
    $this->connectorUser = $role(['social_commerce.view', 'social_commerce.connect'], 'Page admin');
    $this->connector = orgToken($this->connectorUser, $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/social/{$path}";

    $inv = fn (string $path) => "/api/organizations/{$this->w->c1->id}/inventory/{$path}";
    $pcs = $this->asToken($this->keeper)->postJson($inv('units'), ['code' => 'PCS', 'name' => ['en' => 'Pieces'], 'decimals' => 0])->assertCreated()->json('data');
    $this->soap = $this->asToken($this->keeper)->postJson($inv('items'), ['unit_id' => $pcs['id'], 'kind' => 'stock', 'sku' => 'SOAP', 'name' => ['en' => 'Soap', 'bn' => 'সাবান'], 'sale_price_minor' => 11500])->assertCreated()->json('data');

    ruleService()->set(app(RuleTargets::class)->organization($this->w->c1->fresh()), 'social_commerce.post_footer', RuleMode::Set,
        ['en' => 'Inbox us to order', 'bn' => 'অর্ডার করতে ইনবক্স করুন'], 'Test setup', trusted: true);

    // Connecting: start (the state is in Meta's address), Meta sends the person back, pick the pages.
    $this->connect = function (?string $token = null, $unit = null, array $pageIds = ['111']) {
        $url = $this->asToken($token ?? $this->connector)->postJson(($this->api)('connect', $unit))->assertOk()->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->get('/api/social/facebook/callback?'.http_build_query(['state' => $query['state'], 'code' => 'CODE']))->assertRedirect('/social/connect?state='.$query['state']);

        return ['state' => $query['state'], 'url' => $url, 'save' => fn () => $this->asToken($token ?? $this->connector)->postJson(($this->api)('connect/pages', $unit), ['state' => $query['state'], 'page_ids' => $pageIds])];
    };
    $this->page = function () {
        fakeMeta();
        (($this->connect)()['save'])()->assertCreated();

        return SocialPage::query()->withoutGlobalScope(OrganizationScope::class)->where('page_id', '111')->firstOrFail();
    };
    $this->write = fn (array $extra = [], ?string $token = null) => $this->asToken($token ?? $this->writer)->postJson(($this->api)('posts'), [
        'page_id' => SocialPage::query()->withoutGlobalScope(OrganizationScope::class)->where('page_id', '111')->value('id'),
        'locale' => 'en', 'message' => 'New soap in stock!', 'item_ids' => [$this->soap['id']], 'op_id' => (string) str()->ulid(), ...$extra,
    ]);
    $this->step = fn (array $post, string $step, array $body = [], ?string $token = null) => $this->asToken($token ?? $this->writer)
        ->postJson(($this->api)("posts/{$post['id']}/{$step}"), ['base_version' => $post['version'], ...$body]);
});

it('connects a page in three steps without the browser ever seeing a token', function () {
    fakeMeta();
    $flow = ($this->connect)();
    expect($flow['url'])->toStartWith('https://www.facebook.com/v21.0/dialog/oauth?')->toContain('client_id=1234567890')->toContain('pages_manage_posts');

    $candidates = $this->asToken($this->connector)->getJson(($this->api)('connect/candidates').'?state='.$flow['state'])->assertOk();
    expect($candidates->json('data'))->toHaveCount(2)
        ->and($candidates->json('data.0'))->toMatchArray(['page_id' => '111', 'name' => 'BBU Shop', 'can_post' => true, 'taken' => false])
        ->and($candidates->json('data.1.can_post'))->toBeFalse()
        ->and($candidates->getContent())->not->toContain('PAGE_TOKEN')->not->toContain('PERSON_TOKEN');

    // Someone else cannot use this sign-in.
    $this->asToken($this->writer)->getJson(($this->api)('connect/candidates').'?state='.$flow['state'])->assertForbidden();
    $other = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['social_commerce.connect'], 'Second admin')), $this->w->c1);
    $this->asToken($other)->getJson(($this->api)('connect/candidates').'?state='.$flow['state'])->assertStatus(410)->assertJsonPath('code', 'connect_expired');

    $saved = ($flow['save'])()->assertCreated();
    expect($saved->json('data.0'))->toMatchArray(['name' => 'BBU Shop', 'status' => 'connected', 'can_post' => true])
        ->and($saved->getContent())->not->toContain('PAGE_TOKEN');

    $row = SocialPage::query()->withoutGlobalScope(OrganizationScope::class)->where('page_id', '111')->firstOrFail();
    expect($row->token)->toBe('PAGE_TOKEN_111')->and($row->meta_person_id)->toBe('PERSON1')
        ->and(DB::table('social_pages')->where('id', $row->id)->value('token'))->not->toContain('PAGE_TOKEN')
        ->and(AuditLog::where('action', 'social_commerce.page_connected')->count())->toBe(1);

    // The sign-in is used up; the list shows the page.
    $this->asToken($this->connector)->postJson(($this->api)('connect/pages'), ['state' => $flow['state'], 'page_ids' => ['111']])->assertStatus(410);
    $this->asToken($this->writer)->getJson(($this->api)('pages'))->assertOk()
        ->assertJsonPath('data.pages.0.name', 'BBU Shop')->assertJsonPath('data.app.name', 'Partner A Shop')->assertJsonPath('data.can_connect', false);
});

it('comes back from a cancelled or expired sign-in with a clear outcome', function () {
    fakeMeta();
    $url = $this->asToken($this->connector)->postJson(($this->api)('connect'))->json('data.url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $this->get('/api/social/facebook/callback?'.http_build_query(['state' => $query['state'], 'error' => 'access_denied']))->assertRedirect('/social/pages?connect=cancelled');
    $this->get('/api/social/facebook/callback?'.http_build_query(['state' => $query['state'], 'code' => 'CODE']))->assertRedirect('/social/pages?connect=expired');
    $this->get('/api/social/facebook/callback?state=short&code=CODE')->assertRedirect('/social/pages?connect=expired');
});

it('keeps a page with one company: another company sees it taken and cannot connect it', function () {
    ($this->page)();
    $c2Admin = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['social_commerce.view', 'social_commerce.connect'], 'C2 admin')), $this->w->c2);
    $flow = ($this->connect)($c2Admin, $this->w->c2);
    $this->asToken($c2Admin)->getJson(($this->api)('connect/candidates', $this->w->c2).'?state='.$flow['state'])->assertJsonPath('data.0.taken', true);
    ($flow['save'])()->assertStatus(409)->assertJsonPath('code', 'page_taken');
});

it('uses the house Meta app for a partner without one, unless the partner turns that off', function () {
    $c4Admin = orgToken(staffWithRoles($this->w->c4, makeRole($this->w->c4, ['social_commerce.view', 'social_commerce.connect'], 'C4 admin')), $this->w->c4);
    $this->asToken($c4Admin)->getJson(($this->api)('pages', $this->w->c4))->assertOk()->assertJsonPath('data.app', null);
    $this->asToken($c4Admin)->postJson(($this->api)('connect', $this->w->c4))->assertStatus(409)->assertJsonPath('code', 'no_app');

    activeMetaApp(Partner::factory()->house()->create(['name' => 'One Solutions']), '9999999999', 'One Solutions');
    expect($this->asToken($c4Admin)->postJson(($this->api)('connect', $this->w->c4))->assertOk()->json('data.url'))->toContain('client_id=9999999999');

    partnerRule($this->w->partnerB, 'social_commerce.house_app_allowed', false);
    $this->asToken($c4Admin)->postJson(($this->api)('connect', $this->w->c4))->assertStatus(409)->assertJsonPath('code', 'no_app');
    // Partner A's own app is used for its clients whatever the house allows.
    expect($this->asToken($this->connector)->postJson(($this->api)('connect'))->json('data.url'))->toContain('client_id=1234567890');
});

it('writes a post with a product and photos, waits for approval, then puts it on Facebook', function () {
    ($this->page)();
    $items = $this->asToken($this->writer)->getJson(($this->api)('items').'?search=soap&locale=en')->assertOk();
    expect($items->json('data.0.line'))->toContain('Soap')->toContain('115');

    $post = ($this->write)()->assertCreated()->json('data');
    expect($post)->toMatchArray(['status' => 'draft', 'locale' => 'en'])
        ->and($post['products'][0])->toMatchArray(['item_id' => $this->soap['id'], 'price_minor' => 11500, 'currency' => 'BDT'])
        ->and($post['final_message'])->toBe("New soap in stock!\n\nInbox us to order");

    $post = $this->asToken($this->writer)->post(($this->api)("posts/{$post['id']}/media"), ['base_version' => $post['version'], 'file' => UploadedFile::fake()->image('soap.jpg', 800, 800)], ['Accept' => 'application/json'])->assertCreated()->json('data');
    $post = $this->asToken($this->writer)->post(($this->api)("posts/{$post['id']}/media"), ['base_version' => $post['version'], 'file' => UploadedFile::fake()->image('box.png', 600, 600)], ['Accept' => 'application/json'])->assertCreated()->json('data');
    expect($post['media'])->toHaveCount(2);
    // Photos only through a short-lived signed link.
    $this->get($post['media'][0]['url'])->assertOk();
    $this->get(strtok($post['media'][0]['url'], '?'))->assertForbidden();

    // A writer cannot approve; sent on, it waits.
    $pending = ($this->step)($post, 'submit')->assertOk()->assertJsonPath('data.status', 'pending')->json('data');
    ($this->step)($pending, 'approve')->assertForbidden();
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/feed'));

    $done = ($this->step)($pending, 'approve', [], $this->approver)->assertOk()->json('data');
    expect($done)->toMatchArray(['status' => 'published', 'fb_post_id' => '111_999', 'link' => 'https://www.facebook.com/111_999']);
    Http::assertSent(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/111/feed')
        && $request['message'] === "New soap in stock!\n\nInbox us to order"
        && $request['appsecret_proof'] === hash_hmac('sha256', 'PAGE_TOKEN_111', META_SECRET)
        && str_contains((string) $request['attached_media[0]'], 'media_fbid')
        && isset($request['attached_media[1]']));
    expect(AuditLog::where('action', 'social_commerce.post_published')->count())->toBe(1)
        ->and(app(Posts::class)->productsOf($this->w->c1, $done['id']))->toHaveCount(1);

    // A published post cannot be changed or deleted.
    $this->asToken($this->writer)->patchJson(($this->api)("posts/{$done['id']}"), ['base_version' => $done['version'], 'message' => 'Changed'])->assertStatus(409)->assertJsonPath('code', 'wrong_status');
});

it('turns a post down with a reason; the writer changes it and sends it again', function () {
    ($this->page)();
    $post = ($this->write)()->json('data');
    $pending = ($this->step)($post, 'submit')->json('data');
    ($this->step)($pending, 'reject', [], $this->approver)->assertUnprocessable()->assertJsonValidationErrors('reason');
    $rejected = ($this->step)($pending, 'reject', ['reason' => 'Show the price per box'], $this->approver)->assertOk()
        ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.reject_reason', 'Show the price per box')->json('data');
    $changed = $this->asToken($this->writer)->patchJson(($this->api)("posts/{$rejected['id']}"), ['base_version' => $rejected['version'], 'message' => 'Soap, 115 per box'])->assertOk()->json('data');
    ($this->step)($changed, 'submit')->assertOk()->assertJsonPath('data.status', 'pending');
});

it('goes out at its time when approval is off, never sooner than the rule allows', function () {
    ($this->page)();
    ruleService()->set(app(RuleTargets::class)->organization($this->w->c1->fresh()), 'social_commerce.posts_need_approval', RuleMode::Set, false, 'Test setup', trusted: true);

    $soon = ($this->write)(['publish_at' => now()->addMinutes(5)->toIso8601String()])->json('data');
    ($this->step)($soon, 'submit')->assertUnprocessable()->assertJsonPath('code', 'too_soon');

    $later = ($this->write)(['publish_at' => now()->addHour()->toIso8601String()])->json('data');
    ($this->step)($later, 'submit')->assertOk()->assertJsonPath('data.status', 'scheduled');
    expect(app(Posts::class)->sendDue())->toBe(0);

    $this->travel(61)->minutes();
    expect(app(Posts::class)->sendDue())->toBe(1)
        ->and(Post::query()->withoutGlobalScope(OrganizationScope::class)->find($later['id'])->status)->toBe('published');

    // Taken back before its time, it stays a draft.
    $third = ($this->write)(['publish_at' => now()->addHour()->toIso8601String()])->json('data');
    $scheduled = ($this->step)($third, 'submit')->json('data');
    ($this->step)($scheduled, 'withdraw')->assertOk()->assertJsonPath('data.status', 'draft');
});

it('asks for a new sign-in when Facebook stops taking the page token, and tries again when Facebook is busy', function () {
    ($this->page)();
    ruleService()->set(app(RuleTargets::class)->organization($this->w->c1->fresh()), 'social_commerce.posts_need_approval', RuleMode::Set, false, 'Test setup', trusted: true);

    fakeMeta(['/111/feed' => fn () => Http::response(['error' => ['code' => 4, 'message' => 'Too many calls']], 400)]);
    $busy = ($this->step)(($this->write)()->json('data'), 'submit')->assertOk()->json('data');
    expect($busy['status'])->toBe('scheduled')->and($busy['error']['code'])->toBe('rate_limited')
        ->and($busy['publish_at'])->toBe(now()->addMinutes(5)->toIso8601String());

    fakeMeta(['/111/feed' => fn () => Http::response(['error' => ['code' => 190, 'message' => 'Session expired', 'error_user_msg' => 'Please sign in again']], 400)]);
    $failed = ($this->step)(($this->write)()->json('data'), 'submit')->assertOk()->json('data');
    expect($failed['status'])->toBe('failed')->and($failed['error'])->toMatchArray(['code' => 'token_invalid', 'detail' => 'Please sign in again'])
        ->and(SocialPage::query()->withoutGlobalScope(OrganizationScope::class)->where('page_id', '111')->value('status'))->toBe('needs_reconnect');
    // Until it is connected again, nothing can be sent to it.
    ($this->step)(($this->write)()->json('data'), 'submit')->assertStatus(409)->assertJsonPath('code', 'page_unusable');
});

it('marks a post stuck while publishing as failed instead of sending it twice', function () {
    ($this->page)();
    $post = ($this->write)()->json('data');
    Post::query()->withoutGlobalScope(OrganizationScope::class)->whereKey($post['id'])->update(['status' => 'publishing', 'updated_at' => now()->subMinutes(20)]);
    app(Posts::class)->sendDue();
    expect(Post::query()->withoutGlobalScope(OrganizationScope::class)->find($post['id']))->status->toBe('failed')->error_code->toBe('interrupted');
});

it('disconnects a page: its token is forgotten and waiting posts go back to drafts', function () {
    $page = ($this->page)();
    $pending = ($this->step)(($this->write)()->json('data'), 'submit')->json('data');
    $this->asToken($this->writer)->postJson(($this->api)("pages/{$page->id}/disconnect"), ['base_version' => $page->version])->assertForbidden();
    $this->asToken($this->connector)->postJson(($this->api)("pages/{$page->id}/disconnect"), ['base_version' => $page->version])->assertOk()->assertJsonPath('data.status', 'disconnected');
    expect($page->fresh()->token)->toBeNull()
        ->and(Post::query()->withoutGlobalScope(OrganizationScope::class)->find($pending['id'])->status)->toBe('draft');
});

it('checks input, permissions and photos', function () {
    ($this->page)();
    ($this->write)(['organization_id' => $this->w->c2->id])->assertUnprocessable()->assertJsonValidationErrors('organization_id');
    ($this->write)(['locale' => 'xx'])->assertUnprocessable()->assertJsonValidationErrors('locale');
    ($this->write)(['item_ids' => [(string) str()->ulid()]])->assertUnprocessable()->assertJsonPath('code', 'unknown_item');
    ($this->write)([], $this->approver)->assertForbidden();
    $this->asToken($this->approver)->postJson(($this->api)('connect'))->assertForbidden();

    $post = ($this->write)(['message' => null])->json('data');
    ($this->step)($post, 'submit')->assertUnprocessable()->assertJsonPath('code', 'empty_post');
    $this->asToken($this->writer)->post(($this->api)("posts/{$post['id']}/media"), ['base_version' => $post['version'], 'file' => UploadedFile::fake()->create('virus.svg', 10, 'image/svg+xml')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    // A stale version is refused.
    $this->asToken($this->writer)->patchJson(($this->api)("posts/{$post['id']}"), ['base_version' => $post['version'] + 5, 'message' => 'x'])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    // A draft can be deleted.
    $this->asToken($this->writer)->deleteJson(($this->api)("posts/{$post['id']}"), ['base_version' => $post['version']])->assertOk();
});

it('answers 403 everywhere when Facebook selling is off, and keeps the data', function () {
    ($this->page)();
    $post = ($this->write)()->json('data');
    toggles()->disable($this->w->g1, 'social_commerce', 'Stop Facebook selling');
    $this->asToken($this->writer)->getJson(($this->api)('posts'))->assertForbidden();
    $this->asToken($this->connector)->postJson(($this->api)('connect'))->assertForbidden();
    expect(Post::query()->withoutGlobalScope(OrganizationScope::class)->find($post['id']))->not->toBeNull();
});

it('keeps companies and partners apart', function () {
    ($this->page)();
    $post = ($this->write)()->json('data');
    $c2Writer = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['social_commerce.view', 'social_commerce.compose'], 'C2 writer')), $this->w->c2);
    $c4Writer = orgToken(staffWithRoles($this->w->c4, makeRole($this->w->c4, ['social_commerce.view', 'social_commerce.compose'], 'C4 writer')), $this->w->c4);

    foreach ([$c2Writer, $c4Writer] as $token) {
        $this->asToken($token)->getJson(($this->api)('posts'))->assertNotFound();
        $this->asToken($token)->getJson(($this->api)("posts/{$post['id']}"))->assertNotFound();
    }
    $this->asToken($c2Writer)->getJson(($this->api)("posts/{$post['id']}", $this->w->c2))->assertNotFound()->assertJsonPath('code', 'post_not_found');
    expect($this->asToken($c2Writer)->getJson(($this->api)('pages', $this->w->c2))->json('data.pages'))->toBe([])
        ->and($this->asToken($c4Writer)->getJson(($this->api)('posts', $this->w->c4))->json('data'))->toBe([]);
    // Another company's page cannot be posted on.
    $this->asToken($c2Writer)->postJson(($this->api)('posts', $this->w->c2), ['page_id' => SocialPage::query()->withoutGlobalScope(OrganizationScope::class)->value('id'), 'locale' => 'en', 'message' => 'x'])
        ->assertNotFound()->assertJsonPath('code', 'page_not_found');
});
