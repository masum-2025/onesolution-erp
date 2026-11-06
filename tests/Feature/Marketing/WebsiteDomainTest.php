<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Notifications\Services\LinkBuilder;
use App\Platform\Partners\Enums\DomainServes;
use App\Platform\Partners\Models\PartnerDomain;
use App\Platform\Partners\Services\HostResolver;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

/*
 * MKT-3c: a partner's company website on its own verified domain. A domain
 * opens either the app or the website, never both; a website domain shows
 * only its own partner's site, and only once DNS has proven it.
 */

beforeEach(function () {
    config(['marketing.hosts' => 'marketing.test', 'marketing.url' => '']);
    RateLimiter::clear('marketing:127.0.0.1');
    $this->w = tenancyWorld();
    $this->ownerA = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
    $this->ownerB = partnerToken(createPartnerStaff($this->w->partnerB, PartnerUserRole::Owner), $this->w->partnerB);
    foreach ([$this->ownerA, $this->ownerB] as $token) {
        $this->asToken($token)->postJson('/api/partner/website')->assertCreated();
        $this->asToken($token)->postJson('/api/partner/website/publish')->assertOk();
    }
});

/** A website domain as DNS would leave it after "Check now". */
function websiteDomain(Partner $partner, string $host, string $serves = 'website'): PartnerDomain
{
    $domain = PartnerDomain::create(['partner_id' => $partner->id, 'host' => $host, 'serves' => $serves, 'status' => 'active', 'verification_token' => 'x', 'verified_at' => now()]);
    app(HostResolver::class)->forget($host);

    return $domain;
}

function openSite(object $test, string $url, array $headers = [])
{
    $response = $test->get($url, $headers);
    URL::forceRootUrl(config('app.url'));

    return $response;
}

it('adds a website domain for the partner, waiting for DNS like any other', function () {
    $this->asToken($this->ownerA)->postJson('/api/partner/domains', ['host' => 'www.partner-a.test', 'serves' => 'website'])
        ->assertCreated()->assertJsonPath('data.serves', 'website')->assertJsonPath('data.status', 'pending');
    $this->asToken($this->ownerA)->postJson('/api/partner/domains', ['host' => 'erp.partner-a.test'])
        ->assertCreated()->assertJsonPath('data.serves', 'app');

    $audit = AuditLog::query()->where('action', 'partner.domain_added')->where('partner_id', $this->w->partnerA->id)->latest('id')->get();
    expect($audit->pluck('new_values.serves')->sort()->values()->all())->toBe(['app', 'website']);

    // Not proven yet: the address shows nothing (neither a site nor the app).
    openSite($this, 'http://www.partner-a.test/')->assertNotFound()->assertSee(__('partners.errors.unknown_host'));
    $this->asToken($this->ownerA)->getJson('/api/partner/website')->assertOk()->assertJsonPath('data.site.address', null);
});

it('shows the partner\'s own published site on its verified website domain', function () {
    websiteDomain($this->w->partnerA, 'www.partner-a.test');

    $page = openSite($this, 'http://www.partner-a.test/')->assertOk()
        ->assertSee('<link rel="canonical" href="https://www.partner-a.test/', false);
    expect($page->getContent())->toContain('Partner A')->not->toContain('Partner B');
    openSite($this, 'http://www.partner-a.test/sitemap.xml')->assertOk()->assertSee('https://www.partner-a.test/', false);

    $this->asToken($this->ownerA)->getJson('/api/partner/website')->assertJsonPath('data.site.address', 'https://www.partner-a.test');
    // Partner B's site keeps no address of A's.
    $this->asToken($this->ownerB)->getJson('/api/partner/website')->assertJsonPath('data.site.address', null);
});

it('keeps partners apart: each website domain shows only its partner\'s site', function () {
    websiteDomain($this->w->partnerA, 'www.partner-a.test');
    websiteDomain($this->w->partnerB, 'www.partner-b.test');

    expect(openSite($this, 'http://www.partner-b.test/')->assertOk()->getContent())->toContain('Partner B')->not->toContain('Partner A');
    expect(openSite($this, 'http://www.partner-a.test/')->assertOk()->getContent())->toContain('Partner A')->not->toContain('Partner B');

    // A partner without a site: a plain "not available", never someone else's.
    $partnerC = Partner::factory()->create(['name' => 'Partner C']);
    websiteDomain($partnerC, 'www.partner-c.test');
    expect(openSite($this, 'http://www.partner-c.test/')->assertNotFound()->getContent())->not->toContain('Partner A')->not->toContain('Partner B');
});

it('never opens the app on a website domain, nor the website on an app domain', function () {
    websiteDomain($this->w->partnerA, 'www.partner-a.test');
    websiteDomain($this->w->partnerA, 'erp.partner-a.test', 'app');

    // The app's paths on the website domain are just pages the site does not have.
    openSite($this, 'http://www.partner-a.test/api/me', ['Accept' => 'application/json'])->assertNotFound()->assertHeaderMissing('Set-Cookie');
    $this->call('POST', 'http://www.partner-a.test/api/auth/login', ['email' => 'a@b.test', 'password' => 'x'])->assertStatus(405);
    URL::forceRootUrl(config('app.url'));

    // The app domain still opens the app (no website there).
    expect(openSite($this, 'http://erp.partner-a.test/')->getContent())->not->toContain('<link rel="canonical" href="https://erp.partner-a.test/');

    // Links in messages and the site's "app:" links go to the app domain, never the website one.
    expect(app(LinkBuilder::class)->base($this->w->partnerA))->toBe('https://erp.partner-a.test');
    PartnerDomain::query()->where('host', 'erp.partner-a.test')->delete();
    expect(app(LinkBuilder::class)->base($this->w->partnerA))->toBe(rtrim((string) config('app.url'), '/'));
});

it('refuses a website domain for a client, the platform\'s own website hosts and unknown kinds', function () {
    $this->asToken($this->ownerA)->postJson('/api/partner/domains', ['host' => 'school.partner-a.test', 'organization_id' => $this->w->g1->id, 'serves' => 'website'])
        ->assertUnprocessable()->assertJsonPath('code', 'website_for_client');
    $this->asToken($this->ownerA)->postJson('/api/partner/domains', ['host' => 'marketing.test', 'serves' => 'website'])
        ->assertUnprocessable()->assertJsonPath('code', 'host_reserved');
    $this->asToken($this->ownerA)->postJson('/api/partner/domains', ['host' => 'www.partner-a.test', 'serves' => 'shop'])
        ->assertUnprocessable()->assertJsonValidationErrors('serves');

    expect(PartnerDomain::query()->where('partner_id', $this->w->partnerA->id)->count())->toBe(0)
        ->and(DomainServes::cases())->toHaveCount(2);
});
