<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Marketing\Models\Block;
use App\Platform\Marketing\Models\Page;
use App\Platform\Marketing\Models\Site;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\URL;

/*
 * MKT-1: a partner's company website, built from pages of sections in the
 * partner console and published as a snapshot; the platform's own (the
 * house partner's) answers its marketing hosts.
 */

beforeEach(function () {
    config(['marketing.hosts' => 'marketing.test', 'marketing.url' => '']);
    $this->w = tenancyWorld();
    $this->house = Partner::query()->where('is_house', true)->first() ?? Partner::factory()->house()->create(['name' => 'One Solutions']);
    $this->owner = partnerToken(createPartnerStaff($this->house, PartnerUserRole::Owner), $this->house);
});

function startHouseSite(object $test): Site
{
    $test->asToken($test->owner)->postJson('/api/partner/website')->assertCreated();

    return Site::query()->where('partner_id', $test->house->id)->firstOrFail();
}

function publishHouseSite(object $test): Site
{
    startHouseSite($test);
    $test->asToken($test->owner)->postJson('/api/partner/website/publish')->assertOk();

    return Site::query()->where('partner_id', $test->house->id)->firstOrFail();
}

it('starts the platform\'s site from the starter, publishes it and serves it on its host in both languages', function () {
    publishHouseSite($this);

    $page = $this->get('http://marketing.test/')->assertOk()
        ->assertSee('One ERP to run your', false)
        ->assertSee('<link rel="canonical" href="http://marketing.test/">', false)
        ->assertSee('hreflang="bn"', false)
        ->assertSee('application/ld+json', false);
    expect($page->headers->get('Content-Security-Policy'))->toContain("script-src 'self'")->toContain("style-src 'self'")->toContain("frame-ancestors 'none'")
        ->and($page->getContent())->not->toContain('style="');

    $this->get('http://marketing.test/bn')->assertOk()->assertSee('এক সফটওয়্যারে চালান আপনার', false)->assertSee('lang="bn"', false);
    $this->get('http://marketing.test/pricing')->assertOk()->assertSee('৳1,500', false)->assertSee('FAQPage', false);
    $this->get('http://marketing.test/bn/pricing')->assertOk()->assertSee('৳১,৫০০', false);
    $this->get('http://marketing.test/no-such-page')->assertNotFound();

    $this->get('http://marketing.test/sitemap.xml')->assertOk()->assertSee('http://marketing.test/bn/pricing', false);
    $this->get('http://marketing.test/robots.txt')->assertOk()->assertSee('Sitemap: http://marketing.test/sitemap.xml', false);
    $css = $this->get('http://marketing.test/assets/site.css')->assertOk();
    expect($css->getContent())->toStartWith(':root{--blue:#');
    $this->get('http://marketing.test/assets/site.js')->assertOk();
    $this->get('http://marketing.test/assets/fonts/inter-latin.woff2')->assertOk();

    expect(AuditLog::where('action', 'marketing.site_published')->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'marketing.site_created')->exists())->toBeTrue();
});

it('shows nothing until published, and only reads on its host', function () {
    startHouseSite($this);

    $this->get('http://marketing.test/')->assertNotFound()->assertSee(__('marketing.public.unavailable'));
    $this->post('http://marketing.test/')->assertStatus(405);
});

it('shows the drafts behind a signed preview link only', function () {
    $site = startHouseSite($this);
    $url = $this->asToken($this->owner)->postJson('/api/partner/website/preview-link')->assertOk()->json('data.url');

    $preview = $this->get($url)->assertOk()->assertSee('One ERP to run your', false)->assertSee('noindex', false);
    expect($preview->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
    $this->get($url.'&page=pricing&lang=bn')->assertOk()->assertSee('৳১,৫০০', false);
    $this->get('/website-preview/'.$site->id)->assertNotFound();
    $this->get(URL::temporarySignedRoute('marketing.preview', now()->subMinute(), ['site' => $site->id]))->assertNotFound();
});

it('edits pages and sections, checks every value, and leaves a redirect when an address changes', function () {
    $site = startHouseSite($this);
    $token = $this->owner;

    $page = $this->asToken($token)->postJson('/api/partner/website/pages', ['slug' => 'about', 'title' => ['en' => 'About', 'bn' => 'আমাদের কথা']])->assertCreated()->json('data');
    $this->asToken($token)->postJson("/api/partner/website/pages/{$page['id']}/blocks", [
        'type' => 'text', 'data' => ['title' => ['en' => 'Who we are'], 'body' => ['en' => "First.\n\nSecond."]],
    ])->assertCreated();

    $bad = fn (array $body) => $this->asToken($token)->postJson("/api/partner/website/pages/{$page['id']}/blocks", $body)->assertUnprocessable();
    $bad(['type' => 'iframe', 'data' => []])->assertJsonValidationErrors('type');
    $bad(['type' => 'cta', 'data' => ['title' => ['en' => 'Go'], 'primary_label' => ['en' => 'Go'], 'primary_url' => 'javascript:alert(1)']])->assertJsonValidationErrors('data.primary_url');
    $bad(['type' => 'cards', 'data' => ['items' => [['icon' => 'school', 'color' => 'red;}', 'title' => ['en' => 'X']]]]])->assertJsonValidationErrors('data.items.0.color');
    $bad(['type' => 'text', 'data' => ['body' => ['en' => 'x']], 'site_id' => 'x'])->assertJsonValidationErrors('site_id');
    $this->asToken($token)->postJson('/api/partner/website/pages', ['slug' => 'sitemap.xml', 'title' => ['en' => 'x']])->assertUnprocessable();
    $this->asToken($token)->postJson('/api/partner/website/pages', ['slug' => 'robots', 'title' => ['en' => 'x']])->assertCreated();
    $this->asToken($token)->postJson('/api/partner/website/pages', ['slug' => 'about', 'title' => ['en' => 'x']])->assertUnprocessable()->assertJsonPath('code', 'slug_taken');

    // One change at a time.
    $this->asToken($token)->patchJson("/api/partner/website/pages/{$page['id']}", ['base_version' => 1, 'slug' => 'about-us'])->assertOk();
    $this->asToken($token)->patchJson("/api/partner/website/pages/{$page['id']}", ['base_version' => 1, 'in_menu' => false])->assertStatus(409)->assertJsonPath('code', 'changed_meanwhile');

    $home = Page::query()->where('site_id', $site->id)->where('is_home', true)->value('id');
    $this->asToken($token)->deleteJson("/api/partner/website/pages/{$home}")->assertUnprocessable()->assertJsonPath('code', 'home_stays');

    $this->asToken($token)->postJson('/api/partner/website/publish')->assertOk();
    $this->get('http://marketing.test/about-us')->assertOk()->assertSee('Who we are');
    $this->get('http://marketing.test/about')->assertRedirect('/about-us')->assertStatus(301);
    $this->get('http://marketing.test/bn/about')->assertRedirect('/bn/about-us');
});

it('hides an offer past its end and a section outside its days', function () {
    $site = startHouseSite($this);
    $offer = Block::query()->where('site_id', $site->id)->where('type', 'offer')->firstOrFail();
    $this->asToken($this->owner)->patchJson("/api/partner/website/blocks/{$offer->id}", [
        'base_version' => 1, 'data' => [...$offer->data, 'ends_at' => now()->subDay()->format('Y-m-d\TH:i')],
    ])->assertOk();
    $faq = Block::query()->where('site_id', $site->id)->where('type', 'faq')->firstOrFail();
    $this->asToken($this->owner)->patchJson("/api/partner/website/blocks/{$faq->id}", ['base_version' => 1, 'visible_until' => now()->subDay()->toDateString()])->assertOk();
    $this->asToken($this->owner)->postJson('/api/partner/website/publish')->assertOk();

    $this->get('http://marketing.test/')->assertOk()->assertDontSee('id="offer"', false)->assertDontSee('id="faq"', false);
});

it('lets owners change settings, and brings an earlier version back', function () {
    $site = publishHouseSite($this);
    $first = $this->asToken($this->owner)->getJson('/api/partner/website/versions')->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
    expect($first)->toBeString();

    $this->asToken($this->owner)->patchJson('/api/partner/website', ['base_version' => 1, 'template' => 'studio', 'settings' => ['colors' => ['primary' => '#000000;}']]])
        ->assertUnprocessable()->assertJsonValidationErrors('settings.colors.primary');
    $this->asToken($this->owner)->patchJson('/api/partner/website', ['base_version' => 1, 'template' => 'studio', 'default_locale' => 'bn', 'locales' => ['en']])
        ->assertUnprocessable()->assertJsonPath('code', 'main_locale_missing');
    $this->asToken($this->owner)->patchJson('/api/partner/website', ['base_version' => 1, 'template' => 'studio', 'settings' => ['colors' => ['primary' => '#0F766E']]])->assertOk();
    $this->asToken($this->owner)->postJson('/api/partner/website/publish')->assertOk();
    $this->get('http://marketing.test/')->assertSee('class="tpl-studio"', false);
    expect($this->get('http://marketing.test/assets/site.css')->getContent())->toStartWith(':root{--blue:#0F766E');

    // Requests to the marketing host move the test client's address; the console is on the app's.
    URL::forceRootUrl(config('app.url'));
    $this->asToken($this->owner)->postJson("/api/partner/website/versions/{$first}/restore")->assertOk();
    $this->get('http://marketing.test/')->assertSee('class="tpl-aurora"', false);
    expect($site->refresh()->template)->toBe('aurora');
});

it('gives each partner role only its part', function () {
    startHouseSite($this);
    $sales = partnerToken(createPartnerStaff($this->house, PartnerUserRole::Sales), $this->house);
    $support = partnerToken(createPartnerStaff($this->house, PartnerUserRole::Support), $this->house);

    $this->asToken($support)->getJson('/api/partner/website')->assertOk()->assertJsonPath('data.can.write', false);
    $this->asToken($support)->postJson('/api/partner/website/pages', ['slug' => 'x', 'title' => ['en' => 'X']])->assertForbidden();
    $this->asToken($sales)->postJson('/api/partner/website/pages', ['slug' => 'x', 'title' => ['en' => 'X']])->assertCreated();
    $this->asToken($sales)->postJson('/api/partner/website/publish')->assertForbidden();
    $this->asToken($sales)->patchJson('/api/partner/website', ['base_version' => 1, 'template' => 'studio'])->assertForbidden();
});

it('keeps partners apart and keeps prices to the platform\'s site', function () {
    $houseSite = startHouseSite($this);
    $homeA = Page::query()->where('site_id', $houseSite->id)->where('is_home', true)->value('id');
    $block = Block::query()->where('site_id', $houseSite->id)->value('id');

    $tokenA = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
    $this->asToken($tokenA)->getJson('/api/partner/website')->assertOk()->assertJsonPath('data.site', null);
    $this->asToken($tokenA)->postJson('/api/partner/website')->assertCreated()->assertJsonPath('data.site.template', 'corporate');

    // Another partner's pages and sections are simply not there.
    $this->asToken($tokenA)->postJson("/api/partner/website/pages/{$homeA}/blocks", ['type' => 'text', 'data' => ['body' => ['en' => 'x']]])->assertNotFound();
    $this->asToken($tokenA)->deleteJson("/api/partner/website/blocks/{$block}")->assertNotFound();
    $this->asToken($tokenA)->patchJson("/api/partner/website/pages/{$homeA}", ['base_version' => 1, 'in_menu' => false])->assertNotFound();
    expect(Block::query()->whereKey($block)->exists())->toBeTrue();

    $ownHome = Page::query()->where('site_id', Site::query()->where('partner_id', $this->w->partnerA->id)->value('id'))->where('is_home', true)->value('id');
    $this->asToken($tokenA)->postJson("/api/partner/website/pages/{$ownHome}/blocks", [
        'type' => 'pricing', 'data' => ['plans' => ['starter'], 'currency' => 'BDT'],
    ])->assertUnprocessable()->assertJsonPath('code', 'pricing_house_only');

    // The partner's own draft never shows on the platform's host.
    $this->asToken($tokenA)->postJson('/api/partner/website/publish')->assertOk();
    $this->get('http://marketing.test/')->assertNotFound();
});
