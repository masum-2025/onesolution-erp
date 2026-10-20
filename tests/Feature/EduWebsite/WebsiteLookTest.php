<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * WEB-2b: a premium look. A picture slider (checked slides; swipe without
 * the script, turning by itself with it), galleries with captions in a
 * grid, masonry or strip (older galleries of ids alone still show),
 * "in numbers" cards in the visitor's digits, a premium template, a "new"
 * mark on recent notices, the site's own fonts, and our own small script
 * allowed by its hash (nothing a school wrote ever inside it).
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 04:00:00', 'UTC'));
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'education', 'Test setup');
        toggles()->enable($group, 'edu_website', 'Test setup');
    }
    $this->token = orgToken(createMember($this->w->c1), $this->w->c1);
    $this->api = fn (string $path = '') => '/api/organizations/'.$this->w->c1->id.'/website'.($path === '' ? '' : "/{$path}");
    $this->asToken($this->token)->postJson(($this->api)(), [
        'slug' => 'abc-school', 'title' => ['en' => 'ABC High School', 'bn' => 'এবিসি উচ্চ বিদ্যালয়'], 'template' => 'school', 'default_locale' => 'en',
    ])->assertCreated();
    $this->home = $this->asToken($this->token)->getJson(($this->api)('pages'))->json('data.0');
    $this->add = fn (string $type, array $data) => $this->asToken($this->token)->postJson(($this->api)("pages/{$this->home['id']}/blocks"), ['type' => $type, 'data' => $data]);
    $this->picture = function (string $name) {
        ob_start();
        imagepng(imagecreatetruecolor(800, 500));
        $file = UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());

        return $this->asToken($this->token)->post(($this->api)('media'), ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    };
    $this->publish = function () {
        $site = $this->asToken($this->token)->getJson(($this->api)())->json('data');
        $this->asToken($this->token)->postJson(($this->api)('publish'), ['base_version' => $site['version']])->assertOk();
    };
});

it('checks slides: a picture of the site each, safe links, a few seconds each', function () {
    $picture = ($this->picture)('Campus.png');

    ($this->add)('slider', ['slides' => []])->assertUnprocessable()->assertJsonValidationErrors('data.slides');
    ($this->add)('slider', ['slides' => [['title' => ['en' => 'No picture']]]])->assertUnprocessable()->assertJsonValidationErrors('data.slides.0.image_media_id');
    ($this->add)('slider', ['slides' => [['image_media_id' => $picture, 'button_url' => 'javascript:alert(1)']]])->assertUnprocessable()->assertJsonValidationErrors('data.slides.0.button_url');
    ($this->add)('slider', ['slides' => [['image_media_id' => $picture]], 'interval' => 30])->assertUnprocessable()->assertJsonValidationErrors('data.interval');
    ($this->add)('slider', ['slides' => [['image_media_id' => str_repeat('A', 26)]]])->assertNotFound()->assertJsonPath('code', 'media_not_found');
    ($this->add)('slider', ['slides' => array_fill(0, 9, ['image_media_id' => $picture])])->assertUnprocessable()->assertJsonValidationErrors('data.slides');

    $block = ($this->add)('slider', ['slides' => [['image_media_id' => $picture, 'title' => ['en' => 'Welcome']]], 'interval' => 6, 'effect' => 'slide', 'colour' => 'red'])->assertCreated()->json('data');
    expect($block['data'])->not->toHaveKey('colour')->and($block['data']['interval'])->toBe(6);

    // A picture in a slide is in use.
    $this->asToken($this->token)->deleteJson(($this->api)("media/{$picture}"))->assertConflict()->assertJsonPath('code', 'media_in_use');
});

it('shows the slider: the first picture first, arrows and dots for the script, a heading for the page', function () {
    $first = ($this->picture)('Campus.png');
    $second = ($this->picture)('Sports.png');
    ($this->add)('slider', ['slides' => [
        ['image_media_id' => $first, 'title' => ['en' => 'Welcome to ABC', 'bn' => 'এবিসিতে স্বাগতম'], 'button_label' => ['en' => 'Admission'], 'button_url' => '/admission'],
        ['image_media_id' => $second, 'title' => ['en' => '<b>Sports</b> day']],
    ], 'autoplay' => true, 'interval' => 7])->assertCreated();
    // The slider first on the page.
    $blocks = collect($this->asToken($this->token)->getJson(($this->api)('pages'))->json('data.0.blocks'));
    $this->asToken($this->token)->postJson(($this->api)("pages/{$this->home['id']}/blocks/order"), ['ids' => [$blocks->last()['id'], ...$blocks->slice(0, -1)->pluck('id')]])->assertOk();
    ($this->publish)();

    $html = $this->get('/s/abc-school?lang=bn')->assertOk()->getContent();
    expect($html)->toContain('class="slider fx-fade h-normal"')->toContain('data-interval="7"')->toContain('data-autoplay="1"')
        ->toContain('<h1>এবিসিতে স্বাগতম</h1>')
        ->toContain('fetchpriority="high"')->toContain('loading="lazy"')
        ->toContain('class="dots" hidden')
        ->toContain('&lt;b&gt;Sports&lt;/b&gt; day')->not->toContain('<b>Sports</b>');
    // The swipe still works without the script: no slide is hidden in the markup.
    expect(substr_count($html, 'class="slide '))->toBe(2);
});

it('allows only our own script, by its hash, with nothing a school wrote inside it', function () {
    ($this->add)('text', ['title' => ['en' => 'About'], 'body' => ['en' => 'Since 1972.']])->assertCreated();
    ($this->publish)();

    $response = $this->get('/s/abc-school?lang=en')->assertOk();
    $html = $response->getContent();
    expect(preg_match_all('#<script>(.*?)</script>#s', $html, $scripts))->toBe(1);
    $script = $scripts[1][0];
    $policy = $response->headers->get('Content-Security-Policy');
    expect($policy)->toContain("script-src 'sha256-".base64_encode(hash('sha256', $script, true))."'")
        ->toContain("font-src 'self'")->toContain("default-src 'none'")->not->toContain('unsafe-inline')
        ->and($script)->not->toContain('ABC High School')->not->toContain('Since 1972')->not->toContain('abc-school')
        // The same script on every site and page (so a kept page needs no nonce).
        ->and($script)->toBe(view('edu_website::site-js')->render());
});

it('shows galleries with captions in the layout chosen, and older galleries of ids alone', function () {
    $one = ($this->picture)('One.png');
    $two = ($this->picture)('Two.png');
    ($this->add)('gallery', ['items' => [['image_media_id' => $one, 'caption' => ['en' => 'Prize <giving>']], ['image_media_id' => $two]], 'layout' => 'masonry'])->assertCreated();
    ($this->add)('gallery', ['media_ids' => [$two]])->assertCreated();
    ($this->add)('gallery', ['items' => [['caption' => ['en' => 'No picture']]]])->assertUnprocessable()->assertJsonValidationErrors('data.items.0.image_media_id');
    ($this->add)('gallery', ['layout' => 'mosaic', 'media_ids' => [$two]])->assertUnprocessable()->assertJsonValidationErrors('data.layout');
    ($this->publish)();

    $html = $this->get('/s/abc-school?lang=en')->assertOk()->getContent();
    expect($html)->toContain('class="gallery masonry"')->toContain('class="gallery grid"')
        ->toContain('data-caption="Prize &lt;giving&gt;"')->toContain('<span class="caption">Prize &lt;giving&gt;</span>')
        ->toContain('<dialog id="lightbox"')
        ->and(substr_count($html, 'data-full data-caption='))->toBe(3);
});

it('writes the numbers in the visitor\'s digits and lets the script count up to them', function () {
    ($this->add)('stats', ['items' => [['value' => 1200, 'suffix' => '+', 'label' => ['en' => 'Students', 'bn' => 'শিক্ষার্থী']], ['value' => 52, 'label' => ['en' => 'Years']]]])->assertCreated();
    ($this->add)('stats', ['items' => [['value' => -1, 'label' => ['en' => 'Wrong']]]])->assertUnprocessable()->assertJsonValidationErrors('data.items.0.value');
    ($this->add)('stats', ['items' => [['value' => 5]]])->assertUnprocessable()->assertJsonValidationErrors('data.items.0.label');
    ($this->publish)();

    expect($this->get('/s/abc-school?lang=bn')->assertOk()->getContent())->toContain('data-count="1200">১,২০০</b><span class="suffix">+</span>')->toContain('শিক্ষার্থী');
    expect($this->get('/s/abc-school?lang=en')->getContent())->toContain('data-count="1200">1,200</b>');
});

it('offers the premium template, marks recent notices and serves the site\'s own fonts', function () {
    $site = $this->asToken($this->token)->getJson(($this->api)())->assertOk();
    expect(collect($site->json('meta.template_choices'))->pluck('key')->all())->toBe(['school', 'minimal', 'premium']);
    $this->asToken($this->token)->patchJson(($this->api)(), ['base_version' => $site->json('data.version'), 'template' => 'premium'])->assertOk();
    $notice = fn (string $title, string $date) => $this->asToken($this->token)->postJson(($this->api)('notices'), ['title' => ['en' => $title], 'published_on' => $date, 'is_published' => true])->assertCreated();
    $notice('Fresh notice', '2026-10-12');
    $notice('Old notice', '2026-09-01');
    ($this->publish)();

    $html = $this->get('/s/abc-school/notices?lang=en')->assertOk()->getContent();
    expect($html)->toContain('<body class="tpl-premium">')
        ->and(substr_count($html, '<span class="new">New</span>'))->toBe(1)
        ->and($html)->toContain('url(/s/abc-school/fonts/hind-bengali-400.woff2)');

    $this->get('/s/abc-school/fonts/inter-latin.woff2')->assertOk()->assertHeader('Content-Type', 'font/woff2')->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    $this->get('/s/abc-school/fonts/../../.env')->assertNotFound();
    $this->get('/s/abc-school/fonts/other.woff2')->assertNotFound();

    trustedOrgRule($this->w->c1, 'edu_website.templates', ['school']);
    $site = $this->asToken($this->token)->getJson(($this->api)())->json();
    expect($site['meta']['template_choices'][2])->toBe(['key' => 'premium', 'offered' => false]);
});
