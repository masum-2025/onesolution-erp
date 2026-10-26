<?php

use App\Platform\Tenancy\Enums\MembershipType;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * WEB-2: what the website screens need from the server. The settings
 * screen learns which templates are offered (and where a level above
 * turned one off), the limits with their source, the page count and
 * whether the draft differs from what was published; an address can be
 * checked while typed (publishers only, a few a minute); pages are put in
 * menu order at once; pictures say whether they are used; the website's
 * languages can change (the main one stays among them); a dashboard count
 * and a bell item for publishers. Module off, permissions and isolation.
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

    $this->api = fn (string $path = '', $org = null) => '/api/organizations/'.($org ?? $this->w->c1)->id.'/website'.($path === '' ? '' : "/{$path}");
    $this->asToken($this->token)->postJson(($this->api)(), [
        'slug' => 'abc-school', 'title' => ['en' => 'ABC High School', 'bn' => 'এবিসি উচ্চ বিদ্যালয়'], 'template' => 'school', 'default_locale' => 'en',
    ])->assertCreated();
    $this->site = fn (?string $token = null) => $this->asToken($token ?? $this->token)->getJson(($this->api)())->assertOk()->json();
});

it('tells the screens the templates offered, the limits with their source and whether there is something to publish', function () {
    $meta = ($this->site)()['meta'];
    expect($meta['template_choices'])->toBe([['key' => 'school', 'offered' => true], ['key' => 'minimal', 'offered' => true], ['key' => 'premium', 'offered' => true], ['key' => 'campus', 'offered' => true], ['key' => 'madrasa', 'offered' => true]])
        ->and($meta['template_source']['kind'])->toBe('default')
        ->and($meta['max_pages']['value'])->toBe(12)
        ->and($meta['page_count'])->toBe(1)
        ->and($meta['has_changes'])->toBeTrue()
        ->and($meta['languages'])->toContain('bn', 'en')
        ->and($meta['can_manage'])->toBeTrue()->and($meta['can_publish'])->toBeTrue();

    partnerRule($this->w->partnerA, 'edu_website.templates', ['school']);
    partnerRule($this->w->partnerA, 'edu_website.max_pages', 5);
    $meta = ($this->site)()['meta'];
    expect($meta['template_choices'][1])->toBe(['key' => 'minimal', 'offered' => false])
        ->and($meta['template_source']['kind'])->toBe('inherited')
        ->and($meta['max_pages'])->toMatchArray(['value' => 5])->and($meta['max_pages']['source']['kind'])->toBe('inherited');

    // Published: nothing waits until a page changes.
    $site = ($this->site)()['data'];
    $this->asToken($this->token)->postJson(($this->api)('publish'), ['base_version' => $site['version']])->assertOk();
    expect(($this->site)()['meta']['has_changes'])->toBeFalse();
    $home = $this->asToken($this->token)->getJson(($this->api)('pages'))->json('data.0');
    $this->asToken($this->token)->postJson(($this->api)("pages/{$home['id']}/blocks"), ['type' => 'text', 'data' => ['body' => ['en' => 'Since 1972.']]])->assertCreated();
    expect(($this->site)()['meta']['has_changes'])->toBeTrue();
});

it('checks an address while it is typed, for publishers only', function () {
    $check = fn (string $slug, ?string $token = null, $org = null) => $this->asToken($token ?? $this->token)->getJson(($this->api)('address-check', $org).'?slug='.urlencode($slug));

    $check('Abc-School')->assertOk()->assertJson(['data' => ['slug' => 'abc-school', 'valid' => true, 'available' => true]]);
    $check('-bad-')->assertOk()->assertJson(['data' => ['valid' => false, 'available' => false]]);
    $check('ab')->assertOk()->assertJsonPath('data.valid', false);

    $other = orgToken(createMember($this->w->c2, MembershipType::Owner), $this->w->c2);
    $check('abc-school', $other, $this->w->c2)->assertOk()->assertJsonPath('data.available', false);
    $check('xyz-college', $other, $this->w->c2)->assertOk()->assertJsonPath('data.available', true);

    $editor = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['edu_website.view', 'edu_website.manage'], 'Editor')), $this->w->c1);
    $check('abc-school', $editor)->assertForbidden();
    $check('')->assertUnprocessable();
});

it('puts pages in menu order at once, and only the pages of this website', function () {
    $add = fn (string $slug) => $this->asToken($this->token)->postJson(($this->api)('pages'), ['slug' => $slug, 'title' => ['en' => ucfirst($slug)]])->assertCreated()->json('data.id');
    $about = $add('about');
    $staff = $add('staff');
    $home = $this->asToken($this->token)->getJson(($this->api)('pages'))->json('data.0.id');

    $this->asToken($this->token)->postJson(($this->api)('pages/order'), ['ids' => [$staff, $home, $about, str_repeat('A', 26)]])->assertOk();
    expect(collect($this->asToken($this->token)->getJson(($this->api)('pages'))->json('data'))->pluck('slug')->all())->toBe(['staff', 'home', 'about']);

    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['edu_website.view'], 'Viewer')), $this->w->c1);
    $this->asToken($viewer)->postJson(($this->api)('pages/order'), ['ids' => [$home]])->assertForbidden();
});

it('says which pictures are used, and changes the languages of the website', function () {
    ob_start();
    imagepng(imagecreatetruecolor(400, 300));
    $png = (string) ob_get_clean();
    $upload = fn (string $name) => $this->asToken($this->token)->post(($this->api)('media'), ['file' => UploadedFile::fake()->createWithContent($name, $png)], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $logo = $upload('logo.png');
    $spare = $upload('spare.png');

    $site = ($this->site)()['data'];
    $this->asToken($this->token)->patchJson(($this->api)(), ['base_version' => $site['version'], 'logo_media_id' => $logo])->assertOk();
    $media = collect($this->asToken($this->token)->getJson(($this->api)('media'))->assertOk()->json('data'))->keyBy('id');
    expect($media[$logo]['in_use'])->toBeTrue()->and($media[$spare]['in_use'])->toBeFalse();

    $site = ($this->site)()['data'];
    $this->asToken($this->token)->patchJson(($this->api)(), ['base_version' => $site['version'], 'locales' => ['bn']])
        ->assertUnprocessable()->assertJsonPath('code', 'default_locale_off');
    $this->asToken($this->token)->patchJson(($this->api)(), ['base_version' => $site['version'], 'locales' => ['bn'], 'default_locale' => 'bn'])
        ->assertOk()->assertJsonPath('data.locales', ['bn']);
    $this->asToken($this->token)->patchJson(($this->api)(), ['base_version' => $site['version'] + 1, 'locales' => ['fr']])->assertUnprocessable()->assertJsonValidationErrors('locales.0');
});

it('counts this month\'s notices on the dashboard and tells publishers about unpublished changes', function () {
    $notice = fn (string $date) => $this->asToken($this->token)->postJson(($this->api)('notices'), ['title' => ['en' => 'Notice'], 'published_on' => $date, 'is_published' => true])->assertCreated();
    $notice('2026-10-02');
    $notice('2026-10-14');
    $notice('2026-09-30');
    $this->asToken($this->token)->getJson('/api/modules/edu_website/dashboard/notices')->assertOk()->assertJsonPath('data.value', 2);

    // Never published: the overview says so; the bell waits for a first publish.
    $keys = fn (string $token) => collect($this->asToken($token)->getJson('/api/attention')->assertOk()->json('data'))->pluck('key')->all();
    expect($keys($this->token))->not->toContain('edu_website.unpublished');
    $site = ($this->site)()['data'];
    $this->asToken($this->token)->postJson(($this->api)('publish'), ['base_version' => $site['version']])->assertOk();
    $this->asToken($this->token)->postJson(($this->api)('pages'), ['slug' => 'about', 'title' => ['en' => 'About']])->assertCreated();
    expect($keys($this->token))->toContain('edu_website.unpublished');

    $editor = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['edu_website.view', 'edu_website.manage'], 'Editor')), $this->w->c1);
    expect($keys($editor))->not->toContain('edu_website.unpublished');
});

it('refuses the new endpoints while the module is off, and keeps them to the company', function () {
    $sister = orgToken(createMember($this->w->c2, MembershipType::Owner), $this->w->c2);
    $this->asToken($sister)->postJson(($this->api)('pages/order', $this->w->c2), ['ids' => [str_repeat('A', 26)]])->assertNotFound()->assertJsonPath('code', 'no_site');
    expect($this->asToken($sister)->getJson(($this->api)('address-check').'?slug=abc')->status())->toBeIn([403, 404]);

    toggles()->disable($this->w->g1, 'edu_website', 'Not now');
    $this->asToken($this->token)->getJson(($this->api)('address-check').'?slug=abc-school')->assertForbidden();
    $this->asToken($this->token)->postJson(($this->api)('pages/order'), ['ids' => [str_repeat('A', 26)]])->assertForbidden();
});
