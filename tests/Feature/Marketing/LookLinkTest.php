<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Marketing\Models\Site;
use App\Platform\Partners\Models\PartnerDomain;
use App\Platform\Partners\Services\HostResolver;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

/*
 * MKT-5b: a look link. The owner makes a secret link to the public website;
 * whoever holds it sees the Customize panel there and tries the looks
 * (template, slider effect, product style, colours). Nothing is saved, the
 * page is never cached or indexed, and normal visitors never see the panel.
 */

beforeEach(function () {
    config(['marketing.hosts' => 'marketing.test', 'marketing.url' => '']);
    RateLimiter::clear('marketing:127.0.0.1');
    $this->w = tenancyWorld();
    $this->ownerA = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
    $this->ownerB = partnerToken(createPartnerStaff($this->w->partnerB, PartnerUserRole::Owner), $this->w->partnerB);
    $this->asToken($this->ownerA)->postJson('/api/partner/website')->assertCreated();
    $this->asToken($this->ownerB)->postJson('/api/partner/website')->assertCreated();
    PartnerDomain::create(['partner_id' => $this->w->partnerA->id, 'host' => 'www.partner-a.test', 'serves' => 'website', 'status' => 'active', 'verification_token' => 'x', 'verified_at' => now()]);
    app(HostResolver::class)->forget('www.partner-a.test');
});

function lookPage(object $test, string $url)
{
    $response = $test->get($url);
    URL::forceRootUrl(config('app.url'));

    return $response;
}

function lookToken(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query['look'];
}

it('needs the website live at its address before a look link is made', function () {
    $this->asToken($this->ownerA)->postJson('/api/partner/website/look-link')
        ->assertUnprocessable()->assertJsonPath('code', 'look_needs_live');
});

it('shows the panel only to whoever holds the link, and saves nothing', function () {
    $this->asToken($this->ownerA)->postJson('/api/partner/website/publish')->assertOk();
    $made = $this->asToken($this->ownerA)->postJson('/api/partner/website/look-link')->assertCreated()->json('data');
    expect($made['url'])->toStartWith('https://www.partner-a.test/?look=');
    $token = lookToken($made['url']);
    $site = Site::query()->where('partner_id', $this->w->partnerA->id)->first();
    $before = [$site->template, $site->version, $site->cache_version];
    $other = collect(Site::TEMPLATES)->first(fn ($template) => $template !== $site->template);

    // A normal visitor: no panel, the page as published.
    lookPage($this, 'http://www.partner-a.test/')->assertOk()->assertDontSee('look-form', false);
    // A wrong token: the same.
    lookPage($this, 'http://www.partner-a.test/?look='.str_repeat('x', 40))->assertOk()->assertDontSee('look-form', false);

    // The link holder tries another template, effect, product style and colours.
    $page = lookPage($this, 'http://www.partner-a.test/?'.http_build_query(['look' => $token, 'template' => $other, 'effect' => 'zoom', 'product' => 'simple', 'palette' => '2']));
    $page->assertOk()->assertSee('look-form', false)->assertSee('class="tpl-'.$other.'"', false)
        ->assertSee('data-effect="zoom"', false)
        // No product section on this site: no product choice in the panel.
        ->assertDontSee('name="product"', false)
        ->assertSee('/assets/look.css?palette=2', false)->assertSee('noindex, nofollow', false)
        // The site's own links keep the panel.
        ->assertSee('look='.$token, false);
    expect($page->headers->get('Cache-Control'))->toContain('no-store')
        ->and($page->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($page->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');

    // Unknown choices are ignored, never shown.
    lookPage($this, 'http://www.partner-a.test/?look='.$token.'&template=evil&palette=99')->assertOk()->assertDontSee('tpl-evil', false)->assertDontSee('look.css', false);
    lookPage($this, 'http://www.partner-a.test/assets/look.css?palette=2')->assertOk()->assertSee('--blue:#047857', false);

    // Nothing changed on the site, and normal visitors still see it as published.
    $site->refresh();
    expect([$site->template, $site->version, $site->cache_version])->toBe($before);
    lookPage($this, 'http://www.partner-a.test/')->assertDontSee('tpl-'.$other, false);
    expect(AuditLog::query()->where('action', 'marketing.look_link_made')->where('partner_id', $this->w->partnerA->id)->exists())->toBeTrue();
});

it('stops working when ended, replaced or out of days', function () {
    $this->asToken($this->ownerA)->postJson('/api/partner/website/publish')->assertOk();
    $first = lookToken($this->asToken($this->ownerA)->postJson('/api/partner/website/look-link')->json('data.url'));
    $second = lookToken($this->asToken($this->ownerA)->postJson('/api/partner/website/look-link')->json('data.url'));

    lookPage($this, 'http://www.partner-a.test/?look='.$first)->assertDontSee('look-form', false);
    lookPage($this, 'http://www.partner-a.test/?look='.$second)->assertSee('look-form', false);
    $this->asToken($this->ownerA)->getJson('/api/partner/website')->assertJsonPath('data.site.look_until', fn ($value) => $value !== null)
        ->assertJsonMissingPath('data.site.look_key');

    $this->travel(config('marketing.look_days') + 1)->days();
    lookPage($this, 'http://www.partner-a.test/?look='.$second)->assertDontSee('look-form', false);
    $this->travelBack();

    $this->asToken($this->ownerA)->deleteJson('/api/partner/website/look-link')->assertOk();
    lookPage($this, 'http://www.partner-a.test/?look='.$second)->assertDontSee('look-form', false);
    expect(AuditLog::query()->where('action', 'marketing.look_link_ended')->exists())->toBeTrue();
});

it('lets only the owner make or end a look link, and only for its own site', function () {
    $this->asToken($this->ownerA)->postJson('/api/partner/website/publish')->assertOk();
    $sales = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Sales), $this->w->partnerA);
    $this->asToken($sales)->postJson('/api/partner/website/look-link')->assertForbidden();
    $this->asToken($sales)->deleteJson('/api/partner/website/look-link')->assertForbidden();

    // Partner A's link opens nothing on partner B's site.
    $tokenA = lookToken($this->asToken($this->ownerA)->postJson('/api/partner/website/look-link')->json('data.url'));
    PartnerDomain::create(['partner_id' => $this->w->partnerB->id, 'host' => 'www.partner-b.test', 'serves' => 'website', 'status' => 'active', 'verification_token' => 'y', 'verified_at' => now()]);
    app(HostResolver::class)->forget('www.partner-b.test');
    $this->asToken($this->ownerB)->postJson('/api/partner/website/publish')->assertOk();
    lookPage($this, 'http://www.partner-b.test/?look='.$tokenA)->assertOk()->assertDontSee('look-form', false);
});
