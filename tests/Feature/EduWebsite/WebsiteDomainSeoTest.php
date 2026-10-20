<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Partners\Contracts\DnsTxtLookup;
use App\Platform\Partners\Enums\DomainStatus;
use App\Platform\Partners\Models\PartnerDomain;
use App\Platform\Tenancy\Enums\MembershipType;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/*
 * WEB-3a: a school's website on its own domain, and search and sharing.
 * The domain is offered by the rule edu_website.own_domain (per plan or
 * partner), never one of the platform's, the sites domain's, a partner's or
 * another site's, and works only once a DNS TXT record proves it; then the
 * sites-domain address sends visitors there. Search engines get one
 * address per page (canonical, the other languages), a sitemap and
 * robots.txt; shared links get a description and a picture; the school is
 * structured data that no text can break out of. Every text needs the
 * site's main language (not always English).
 */

beforeEach(function () {
    Storage::fake('local');
    // Relative addresses stay on the app's host after a request to a site's own host.
    URL::forceRootUrl(config('app.url'));
    $this->travelTo(CarbonImmutable::parse('2026-10-15 04:00:00', 'UTC'));
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'education', 'Test setup');
        toggles()->enable($group, 'edu_website', 'Test setup');
    }
    $this->dns = new class implements DnsTxtLookup
    {
        /** @var array<string, list<string>> */
        public array $records = [];

        public function txt(string $name): array
        {
            return $this->records[$name] ?? [];
        }
    };
    app()->instance(DnsTxtLookup::class, $this->dns);

    $this->token = orgToken(createMember($this->w->c1), $this->w->c1);
    $this->api = fn (string $path = '', $org = null) => '/api/organizations/'.($org ?? $this->w->c1)->id.'/website'.($path === '' ? '' : "/{$path}");
    $this->start = fn (string $locale = 'en', ?string $token = null, $org = null, string $slug = 'abc-school') => $this->asToken($token ?? $this->token)->postJson(($this->api)('', $org), [
        'slug' => $slug, 'title' => ['en' => 'ABC High School', 'bn' => 'এবিসি উচ্চ বিদ্যালয়'], 'template' => 'school', 'default_locale' => $locale,
    ]);
    $this->publish = function () {
        $site = $this->asToken($this->token)->getJson(($this->api)())->json('data');
        $this->asToken($this->token)->postJson(($this->api)('publish'), ['base_version' => $site['version']])->assertOk();
    };
    $this->domain = fn (string $host, ?string $token = null, $org = null) => $this->asToken($token ?? $this->token)->putJson(($this->api)('domain', $org), ['host' => $host]);
    $this->prove = function () {
        $record = collect($this->asToken($this->token)->getJson(($this->api)())->json('data.domain.records'))->firstWhere('type', 'TXT');
        $this->dns->records[$record['name']] = [$record['value']];

        return $this->asToken($this->token)->postJson(($this->api)('domain/verify'));
    };
});

it('offers an own domain only where the plan or partner allows it', function () {
    ($this->start)()->assertCreated();
    expect($this->asToken($this->token)->getJson(($this->api)())->json('meta.own_domain.value'))->toBeFalse();
    ($this->domain)('www.abcschool.edu.bd')->assertForbidden()->assertJsonPath('code', 'own_domain_off');

    partnerRule($this->w->partnerA, 'edu_website.own_domain', true);
    ($this->domain)('www.abcschool.edu.bd')->assertOk()
        ->assertJsonPath('data.domain.host', 'www.abcschool.edu.bd')->assertJsonPath('data.domain.verified', false)
        ->assertJsonPath('data.addresses.site', 'http://abc-school.sites.localhost');
    expect(AuditLog::query()->where('action', 'edu_website.domain_added')->count())->toBe(1);

    // Only people who may publish.
    $editor = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['edu_website.view', 'edu_website.manage'], 'Editor')), $this->w->c1);
    ($this->domain)('www.other.edu.bd', $editor)->assertForbidden();
});

it('never takes the platform\'s names, the sites domain, a partner\'s domain or another site\'s', function () {
    ($this->start)()->assertCreated();
    partnerRule($this->w->partnerA, 'edu_website.own_domain', true);
    partnerRule($this->w->partnerB, 'edu_website.own_domain', true);

    foreach (['localhost', 'other.sites.localhost', 'cname.sites.localhost', 'school.test', '127.0.0.1', 'http://abc.edu.bd/x', 'no_underscores.com'] as $host) {
        ($this->domain)($host)->assertStatus(422);
    }
    PartnerDomain::query()->create(['partner_id' => $this->w->partnerA->id, 'host' => 'erp.partner-a.com', 'status' => DomainStatus::Active, 'verification_token' => str_repeat('x', 40)]);
    ($this->domain)('erp.partner-a.com')->assertConflict()->assertJsonPath('code', 'domain_taken');

    ($this->domain)('WWW.ABCSchool.edu.bd.')->assertOk()->assertJsonPath('data.domain.host', 'www.abcschool.edu.bd');
    $sister = orgToken(createMember($this->w->c2, MembershipType::Owner), $this->w->c2);
    ($this->start)('en', $sister, $this->w->c2, 'xyz-college')->assertCreated();
    ($this->domain)('www.abcschool.edu.bd', $sister, $this->w->c2)->assertConflict()->assertJsonPath('code', 'domain_taken');
});

it('works on its own domain only once DNS proves it, and sends the old address there', function () {
    ($this->start)()->assertCreated();
    ($this->publish)();
    partnerRule($this->w->partnerA, 'edu_website.own_domain', true);
    ($this->domain)('www.abcschool.edu.bd')->assertOk();

    // Not proven yet: the host is nobody's site, no certificate is made, the old address still works.
    $this->get('/s/_tls?domain=www.abcschool.edu.bd')->assertNotFound();
    $this->get('http://abc-school.sites.localhost/')->assertOk();
    $this->asToken($this->token)->postJson(($this->api)('domain/verify'))->assertUnprocessable()
        ->assertJsonPath('code', 'domain_not_proven')->assertJsonPath('record.name', '_onesolution-site.www.abcschool.edu.bd');

    ($this->prove)()->assertOk()->assertJsonPath('data.domain.verified', true)->assertJsonPath('data.addresses.site', 'https://www.abcschool.edu.bd');
    expect(AuditLog::query()->where('action', 'edu_website.domain_verified')->count())->toBe(1);

    $this->get('http://www.abcschool.edu.bd/')->assertOk()->assertSee('ABC High School');
    $this->get('http://abc-school.sites.localhost/notices?lang=bn')->assertStatus(301)->assertRedirect('https://www.abcschool.edu.bd/notices?lang=bn');
    $this->post('http://www.abcschool.edu.bd/')->assertStatus(405);

    // The sites server may make certificates for site names only.
    $this->get('/s/_tls?domain=www.abcschool.edu.bd')->assertOk();
    $this->get('/s/_tls?domain=abc-school.sites.localhost')->assertOk();
    $this->get('/s/_tls?domain=nobody.sites.localhost')->assertNotFound();
    $this->get('/s/_tls?domain=evil.example.com')->assertNotFound();

    // Removed: the domain is nobody's again.
    $this->asToken($this->token)->deleteJson(($this->api)('domain'))->assertOk()->assertJsonPath('data.domain', null);
    $this->get('/s/_tls?domain=www.abcschool.edu.bd')->assertNotFound();
    $this->get('http://abc-school.sites.localhost/')->assertOk();
});

it('gives search engines one address per page, the other languages, a sitemap and robots.txt', function () {
    ($this->start)()->assertCreated();
    $this->asToken($this->token)->postJson(($this->api)('pages'), [
        'slug' => 'about', 'title' => ['en' => 'About us'], 'description' => ['en' => 'Our story since 1972.', 'bn' => '১৯৭২ সাল থেকে আমাদের গল্প।'],
    ])->assertCreated();
    $this->asToken($this->token)->postJson(($this->api)('notices'), ['title' => ['en' => 'Exam routine'], 'published_on' => '2026-10-10', 'is_published' => true])->assertCreated();
    ($this->publish)();
    partnerRule($this->w->partnerA, 'edu_website.own_domain', true);
    ($this->domain)('www.abcschool.edu.bd')->assertOk();
    ($this->prove)()->assertOk();

    $html = $this->get('http://www.abcschool.edu.bd/about')->assertOk()->getContent();
    expect($html)->toContain('<link rel="canonical" href="https://www.abcschool.edu.bd/about">')
        ->toContain('<link rel="alternate" hreflang="bn" href="https://www.abcschool.edu.bd/about?lang=bn">')
        ->toContain('<link rel="alternate" hreflang="x-default" href="https://www.abcschool.edu.bd/about">')
        ->toContain('<meta name="description" content="Our story since 1972.">')
        ->toContain('<meta property="og:title" content="About us · ABC High School">')
        ->toContain('"@type":"EducationalOrganization"');

    $sitemap = $this->get('http://www.abcschool.edu.bd/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
    expect($sitemap)->toContain('<loc>https://www.abcschool.edu.bd/</loc>')->toContain('<loc>https://www.abcschool.edu.bd/about</loc>')
        ->toContain('<loc>https://www.abcschool.edu.bd/notices/');
    $this->get('http://www.abcschool.edu.bd/robots.txt')->assertOk()->assertSee('Sitemap: https://www.abcschool.edu.bd/sitemap.xml');

    // A preview is never indexed and names no canonical address.
    $preview = $this->asToken($this->token)->getJson(($this->api)('preview'))->json('data.url');
    expect($this->get($preview)->assertOk()->getContent())->toContain('noindex')->not->toContain('rel="canonical"');
});

it('keeps the links of each address apart, and no text can leave the structured data', function () {
    ($this->start)()->assertCreated();
    $site = $this->asToken($this->token)->getJson(($this->api)())->json('data');
    $this->asToken($this->token)->patchJson(($this->api)(), ['base_version' => $site['version'], 'title' => ['en' => 'ABC </script><script>alert(1)</script> & Co']])->assertOk();
    $this->asToken($this->token)->postJson(($this->api)('pages'), ['slug' => 'about', 'title' => ['en' => 'About']])->assertCreated();
    ($this->publish)();

    // Seen first on the app's host ("/s/abc-school/…" links), then on the site's own host: its own links.
    $this->get('/s/abc-school')->assertOk()->assertSee('href="/s/abc-school/about', false);
    $html = $this->get('http://abc-school.sites.localhost/')->assertOk()->getContent();
    expect($html)->not->toContain('href="/s/abc-school')->toContain('href="/about"');

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $json);
    expect($html)->not->toContain('<script>alert(1)')
        ->and(json_decode($json[1], true)['name'])->toBe('ABC </script><script>alert(1)</script> & Co')
        ->and($json[1])->not->toContain('<')->not->toContain('&');
});

it('needs every text in the site\'s main language, not always English', function () {
    ($this->start)('bn')->assertCreated();
    ($this->start)('bn', null, null, 'other')->assertStatus(409);
    $home = $this->asToken($this->token)->getJson(($this->api)('pages'))->json('data.0');

    $this->asToken($this->token)->postJson(($this->api)('pages'), ['slug' => 'about', 'title' => ['en' => 'About']])->assertUnprocessable()->assertJsonValidationErrors('title.bn');
    $this->asToken($this->token)->postJson(($this->api)('pages'), ['slug' => 'about', 'title' => ['bn' => 'আমাদের কথা']])->assertCreated();
    $this->asToken($this->token)->postJson(($this->api)("pages/{$home['id']}/blocks"), ['type' => 'text', 'data' => ['body' => ['en' => 'Since 1972.']]])
        ->assertUnprocessable()->assertJsonValidationErrors('data.body.bn');
    $this->asToken($this->token)->postJson(($this->api)("pages/{$home['id']}/blocks"), ['type' => 'text', 'data' => ['body' => ['bn' => '১৯৭২ সাল থেকে।']]])->assertCreated();
    $this->asToken($this->token)->postJson(($this->api)('notices'), ['title' => ['en' => 'Holiday'], 'published_on' => '2026-10-10'])->assertUnprocessable()->assertJsonValidationErrors('title.bn');
    $this->asToken($this->token)->postJson(($this->api)('notices'), ['title' => ['bn' => 'ছুটির নোটিশ'], 'published_on' => '2026-10-10'])->assertCreated();

    // Starting: the name in the main language chosen.
    $sister = orgToken(createMember($this->w->c2, MembershipType::Owner), $this->w->c2);
    $this->asToken($sister)->postJson(($this->api)('', $this->w->c2), ['slug' => 'xyz', 'title' => ['en' => 'XYZ'], 'template' => 'school', 'default_locale' => 'bn'])
        ->assertUnprocessable()->assertJsonValidationErrors('title.bn');
    $this->asToken($sister)->postJson(($this->api)('', $this->w->c2), ['slug' => 'xyz', 'title' => ['bn' => 'এক্সওয়াইজেড'], 'template' => 'school', 'default_locale' => 'bn'])->assertCreated();
});

it('shares a page with its own picture, kept while in use', function () {
    ($this->start)()->assertCreated();
    ob_start();
    imagepng(imagecreatetruecolor(1200, 630));
    $picture = $this->asToken($this->token)->post(($this->api)('media'), ['file' => UploadedFile::fake()->createWithContent('Share.png', (string) ob_get_clean())], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    $this->asToken($this->token)->postJson(($this->api)('pages'), ['slug' => 'about', 'title' => ['en' => 'About'], 'share_media_id' => str_repeat('A', 26)])->assertNotFound()->assertJsonPath('code', 'media_not_found');
    $page = $this->asToken($this->token)->postJson(($this->api)('pages'), ['slug' => 'about', 'title' => ['en' => 'About'], 'share_media_id' => $picture])->assertCreated()->json('data');
    expect($page['share_media_id'])->toBe($picture);
    ($this->publish)();

    $this->get('http://abc-school.sites.localhost/about')->assertOk()
        ->assertSee('<meta property="og:image" content="http://abc-school.sites.localhost/media/'.$picture.'/large">', false);
    $this->asToken($this->token)->deleteJson(($this->api)("media/{$picture}"))->assertConflict()->assertJsonPath('code', 'media_in_use');
});

it('refuses the domain while the module is off, and keeps it to the company', function () {
    ($this->start)()->assertCreated();
    partnerRule($this->w->partnerA, 'edu_website.own_domain', true);
    $sister = orgToken(createMember($this->w->c2, MembershipType::Owner), $this->w->c2);
    $this->asToken($sister)->putJson(($this->api)('domain', $this->w->c2), ['host' => 'www.xyz.edu.bd'])->assertNotFound()->assertJsonPath('code', 'no_site');
    expect($this->asToken($sister)->putJson(($this->api)('domain'), ['host' => 'www.xyz.edu.bd'])->status())->toBeIn([403, 404]);

    toggles()->disable($this->w->g1, 'edu_website', 'Not now');
    ($this->domain)('www.abcschool.edu.bd')->assertForbidden();
});
