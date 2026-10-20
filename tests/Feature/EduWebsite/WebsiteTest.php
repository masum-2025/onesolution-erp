<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Tenancy\Enums\MembershipType;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * WEB-1: a school's light public website. Starting it (an address no one
 * else has, a template the rules offer), pages of checked blocks (never
 * HTML), the notice board, pictures drawn again as WebP and PDFs on the
 * private disk, publishing a snapshot (the public never sees the draft; a
 * signed link previews it), "/s/{slug}" and "{slug}.{sites domain}",
 * programs from Education, offline, module off, permissions and isolation.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 04:00:00', 'UTC'));
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'education', 'Test setup');
        toggles()->enable($group, 'edu_website', 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->asToken($this->token)->postJson("/api/organizations/{$this->w->c1->id}/education/presets/bd_school/apply")->assertOk();

    $this->api = fn (string $path = '', $org = null) => '/api/organizations/'.($org ?? $this->w->c1)->id.'/website'.($path === '' ? '' : "/{$path}");
    $this->start = fn (array $data = [], ?string $token = null, $org = null) => $this->asToken($token ?? $this->token)->postJson(($this->api)('', $org), [
        'slug' => 'abc-school', 'title' => ['en' => 'ABC High School', 'bn' => 'এবিসি উচ্চ বিদ্যালয়'], 'template' => 'school', 'default_locale' => 'bn', ...$data,
    ]);
    $this->publish = function () {
        $site = $this->asToken($this->token)->getJson(($this->api)())->json('data');

        return $this->asToken($this->token)->postJson(($this->api)('publish'), ['base_version' => $site['version']])->assertOk()->json('data');
    };
    $this->home = fn () => collect($this->asToken($this->token)->getJson(($this->api)('pages'))->json('data'))->firstWhere('is_home', true);
});

it('starts a website at an address no one else has, from a template the rules offer', function () {
    $site = ($this->start)()->assertCreated()->json('data');
    expect($site)->toMatchArray(['slug' => 'abc-school', 'template' => 'school', 'status' => 'draft', 'default_locale' => 'bn'])
        ->and($site['addresses']['site'])->toBe('http://abc-school.sites.localhost')
        ->and(collect(($this->home)()['blocks'])->pluck('type')->all())->toBe(['hero', 'notices', 'contact'])
        ->and(AuditLog::query()->where('action', 'edu_website.started')->count())->toBe(1);

    $other = orgToken(createMember($this->w->c2), $this->w->c2);
    ($this->start)([], $other, $this->w->c2)->assertConflict()->assertJsonPath('code', 'slug_taken');
    ($this->start)(['slug' => 'Bad Slug!'], $other, $this->w->c2)->assertUnprocessable()->assertJsonValidationErrors('slug');

    trustedOrgRule($this->w->c2, 'edu_website.templates', ['minimal']);
    ($this->start)(['slug' => 'xyz-college'], $other, $this->w->c2)->assertUnprocessable()->assertJsonPath('code', 'template_closed');
    ($this->start)(['slug' => 'xyz-college', 'template' => 'minimal'], $other, $this->w->c2)->assertCreated();
});

it('shows the public only what was published; a signed link previews the draft', function () {
    ($this->start)()->assertCreated();
    $this->get('/s/abc-school')->assertNotFound()->assertSee('not available');

    $preview = $this->asToken($this->token)->getJson(($this->api)('preview'))->assertOk()->json('data.url');
    $this->get($preview)->assertOk()->assertSee('এবিসি উচ্চ বিদ্যালয়')->assertSee('noindex', false);
    $this->get('/s/abc-school/preview')->assertNotFound();

    ($this->publish)();
    $this->get('/s/abc-school')->assertOk()->assertSee('এবিসি উচ্চ বিদ্যালয়')->assertDontSee('noindex', false)
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    $this->get('/s/abc-school?lang=en')->assertOk()->assertSee('ABC High School');

    // Light and locked down: our own style and script allowed by hash only, no framing, no cookie for visitors.
    $public = $this->get('/s/abc-school')->assertHeader('X-Frame-Options', 'DENY');
    expect($public->headers->get('Content-Security-Policy'))->toContain("style-src 'sha256-")->toContain("default-src 'none'")->toContain("script-src 'sha256-")->not->toContain('unsafe-inline')
        ->and($public->headers->getCookies())->toBe([]);

    // A draft change waits for the next publish.
    $home = ($this->home)();
    $this->asToken($this->token)->postJson(($this->api)("pages/{$home['id']}/blocks"), ['type' => 'text', 'data' => ['title' => ['en' => 'About us'], 'body' => ['en' => 'Since 1972.', 'bn' => '১৯৭২ সাল থেকে।']]])->assertCreated();
    $this->get('/s/abc-school?lang=en')->assertDontSee('Since 1972.');
    ($this->publish)();
    $this->get('/s/abc-school?lang=en')->assertSee('Since 1972.');
    expect(AuditLog::query()->where('action', 'edu_website.published')->count())->toBe(2);

    // The same site on its own host name.
    $this->get('http://abc-school.sites.localhost/')->assertOk()->assertSee('এবিসি উচ্চ বিদ্যালয়');
    $this->get('http://no-such-school.sites.localhost/')->assertNotFound();
    $this->post('http://abc-school.sites.localhost/')->assertStatus(405);
});

it('builds pages from checked blocks only, and escapes every text', function () {
    ($this->start)()->assertCreated();
    $home = ($this->home)();
    $add = fn (array $body) => $this->asToken($this->token)->postJson(($this->api)("pages/{$home['id']}/blocks"), $body);

    $add(['type' => 'text', 'data' => ['title' => ['en' => 'About']]])->assertUnprocessable()->assertJsonValidationErrors('data.body');
    $add(['type' => 'marquee', 'data' => []])->assertUnprocessable()->assertJsonValidationErrors('type');
    $add(['type' => 'hero', 'data' => ['title' => ['en' => 'Hi'], 'button_label' => ['en' => 'Go'], 'button_url' => 'javascript:alert(1)']])
        ->assertUnprocessable()->assertJsonValidationErrors('data.button_url');
    $add(['type' => 'gallery', 'data' => ['media_ids' => [str_repeat('A', 26)]]])->assertNotFound()->assertJsonPath('code', 'media_not_found');

    $block = $add(['type' => 'text', 'data' => ['body' => ['en' => '<script>alert(1)</script> Welcome'], 'colour' => 'red']])->assertCreated()->json('data');
    expect($block['data'])->not->toHaveKey('colour');
    $add(['type' => 'programs', 'data' => ['title' => ['en' => 'Classes'], 'source' => 'education']])->assertCreated();

    ($this->publish)();
    $this->get('/s/abc-school?lang=en')->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false)
        ->assertSee('Class 6');

    // Pages: a limit from the plan's rule; the home page stays.
    partnerRule($this->w->partnerA, 'edu_website.max_pages', 2);
    $this->asToken($this->token)->postJson(($this->api)('pages'), ['slug' => 'about', 'title' => ['en' => 'About']])->assertCreated();
    $this->asToken($this->token)->postJson(($this->api)('pages'), ['slug' => 'staff', 'title' => ['en' => 'Staff']])->assertUnprocessable()->assertJsonPath('code', 'too_many_pages');
    $this->asToken($this->token)->postJson(($this->api)('pages'), ['slug' => 'notices', 'title' => ['en' => 'Clash']])->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->asToken($this->token)->deleteJson(($this->api)("pages/{$home['id']}"), ['base_version' => $home['version']])->assertUnprocessable()->assertJsonPath('code', 'home_needed');
});

it('keeps pictures as small WebP copies and PDFs on the private disk, served by the site', function () {
    ($this->start)()->assertCreated();
    ob_start();
    $image = imagecreatetruecolor(2000, 1000);
    imagepng($image);
    $png = UploadedFile::fake()->createWithContent('Sports day.png', (string) ob_get_clean());

    $media = $this->asToken($this->token)->post(($this->api)('media'), ['file' => $png], ['Accept' => 'application/json'])->assertCreated()->json('data');
    expect($media)->toMatchArray(['kind' => 'image', 'name' => 'Sports day', 'width' => 1600, 'height' => 800]);
    Storage::disk('local')->assertExists("edu_website/{$this->w->c1->id}/{$media['id']}/small.webp");

    $this->get("/s/abc-school/media/{$media['id']}/small")->assertOk()
        ->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->asToken($this->token)->post(($this->api)('media'), ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'hello')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    partnerRule($this->w->partnerA, 'edu_website.max_media_mb', 1);
    $this->asToken($this->token)->post(($this->api)('media'), ['file' => UploadedFile::fake()->create('big.pdf', 2048, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');

    // In use as the logo: it stays until taken out there.
    $site = $this->asToken($this->token)->getJson(($this->api)())->json('data');
    $this->asToken($this->token)->patchJson(($this->api)(), ['base_version' => $site['version'], 'logo_media_id' => $media['id']])->assertOk();
    $this->asToken($this->token)->deleteJson(($this->api)("media/{$media['id']}"))->assertConflict()->assertJsonPath('code', 'media_in_use');
});

it('shows published notices at once, pinned first, never a future or hidden one', function () {
    ($this->start)()->assertCreated();
    ($this->publish)();
    $notice = fn (array $data) => $this->asToken($this->token)->postJson(($this->api)('notices'), ['title' => ['en' => 'Notice'], 'published_on' => '2026-10-14', 'is_published' => true, ...$data])->assertCreated()->json('data');
    $notice(['title' => ['en' => 'Half-yearly exam routine']]);
    $notice(['title' => ['en' => 'School closed on Monday'], 'pinned' => true, 'published_on' => '2026-10-01']);
    $notice(['title' => ['en' => 'Next month trip'], 'published_on' => '2026-11-01']);
    $notice(['title' => ['en' => 'Draft notice'], 'is_published' => false]);

    $html = $this->get('/s/abc-school/notices?lang=en')->assertOk()->getContent();
    expect(strpos($html, 'School closed on Monday'))->toBeLessThan(strpos($html, 'Half-yearly exam routine'))
        ->and($html)->not->toContain('Next month trip')->not->toContain('Draft notice');
});

it('goes offline, and shows nothing while the module is off', function () {
    ($this->start)()->assertCreated();
    $site = ($this->publish)();
    $this->asToken($this->token)->postJson(($this->api)('offline'), ['base_version' => $site['version']])->assertOk()->assertJsonPath('data.status', 'offline');
    $this->get('/s/abc-school')->assertNotFound();
    $site = ($this->publish)();
    $this->get('/s/abc-school')->assertOk();

    toggles()->disable($this->w->g1, 'edu_website', 'Not now');
    $this->get('/s/abc-school')->assertNotFound();
    $this->asToken($this->token)->getJson(($this->api)())->assertForbidden();
});

it('lets only people with the website permissions change it, and keeps it to the company', function () {
    ($this->start)()->assertCreated();
    $editor = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['edu_website.view', 'edu_website.manage'], 'Editor')), $this->w->c1);
    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['edu_website.view'], 'Viewer')), $this->w->c1);

    $this->asToken($viewer)->postJson(($this->api)('pages'), ['slug' => 'about', 'title' => ['en' => 'About']])->assertForbidden();
    $this->asToken($editor)->postJson(($this->api)('pages'), ['slug' => 'about', 'title' => ['en' => 'About']])->assertCreated();
    $site = $this->asToken($editor)->getJson(($this->api)())->json('data');
    $this->asToken($editor)->postJson(($this->api)('publish'), ['base_version' => $site['version']])->assertForbidden();
    $this->asToken($editor)->patchJson(($this->api)(), ['base_version' => $site['version'], 'slug' => 'new-address'])->assertForbidden();

    // Another company sees none of it.
    $sister = orgToken(createMember($this->w->c2, MembershipType::Owner), $this->w->c2);
    $this->asToken($sister)->getJson(($this->api)('', $this->w->c2))->assertOk()->assertJsonPath('data', null);
    $this->asToken($sister)->getJson(($this->api)('pages', $this->w->c2))->assertNotFound();
    expect($this->asToken($sister)->getJson(($this->api)('pages'))->status())->toBeIn([403, 404]);
});
