<?php

use App\Platform\Packaging\SectorCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * WEB-3c: templates for colleges, universities and madrasas. Each kind of
 * institution starts with its own template and home page (the rules
 * edu_website.default_template and edu_website.starter_blocks, set per
 * sector by its package; nothing in code asks which sector it is), a
 * message from the head and an academic calendar, and a line above every
 * page written as the school wants (Arabic too).
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
    $this->start = fn (string $template = 'school') => $this->asToken($this->token)->postJson(($this->api)(), [
        'slug' => 'abc-madrasa', 'title' => ['en' => 'ABC Madrasa', 'bn' => 'এবিসি মাদ্রাসা'], 'template' => $template, 'default_locale' => 'bn',
    ]);
    $this->home = fn () => $this->asToken($this->token)->getJson(($this->api)('pages'))->json('data.0');
    $this->add = fn (string $type, array $data) => $this->asToken($this->token)->postJson(($this->api)('pages/'.($this->home)()['id'].'/blocks'), ['type' => $type, 'data' => $data]);
    $this->publish = function () {
        $site = $this->asToken($this->token)->getJson(($this->api)())->json('data');
        $this->asToken($this->token)->postJson(($this->api)('publish'), ['base_version' => $site['version']])->assertOk();
    };
});

it('gives each kind of institution its own template and home page, from its sector package', function () {
    $rules = fn (string $sector) => collect(app(SectorCatalog::class)->get($sector)->rules)->pluck('value', 'key');
    expect($rules('madrasa')['edu_website.default_template'])->toBe('madrasa')
        ->and($rules('college')['edu_website.default_template'])->toBe('campus')
        ->and($rules('university')['edu_website.starter_blocks'])->toBe(['hero', 'notices', 'calendar', 'contact'])
        ->and($rules('coaching')['edu_website.default_template'])->toBe('minimal');

    // A madrasa (its package's values as company rules): offered first, and its home page.
    trustedOrgRule($this->w->c1, 'edu_website.default_template', 'madrasa');
    trustedOrgRule($this->w->c1, 'edu_website.starter_blocks', ['hero', 'message', 'notices', 'contact']);
    $meta = $this->asToken($this->token)->getJson(($this->api)())->json('meta');
    expect($meta['default_template'])->toBe('madrasa')
        ->and(collect($meta['template_choices'])->pluck('key')->all())->toBe(['school', 'minimal', 'premium', 'campus', 'madrasa']);

    ($this->start)('madrasa')->assertCreated()->assertJsonPath('data.template', 'madrasa');
    $blocks = collect(($this->home)()['blocks']);
    expect($blocks->pluck('type')->all())->toBe(['hero', 'message', 'notices', 'contact'])
        ->and($blocks[1]['data']['title'])->toEqualCanonicalizing(['en' => 'Message from the head', 'bn' => 'প্রধানের বাণী']);

    // The empty message is not shown until it is written.
    ($this->publish)();
    $html = $this->get('/s/abc-madrasa')->assertOk()->getContent();
    expect($html)->toContain('<body class="tpl-madrasa">')->not->toContain('class="message"');
});

it('shows the message from the head and the academic calendar', function () {
    ($this->start)()->assertCreated();
    ob_start();
    imagepng(imagecreatetruecolor(400, 500));
    $photo = $this->asToken($this->token)->post(($this->api)('media'), ['file' => UploadedFile::fake()->createWithContent('Principal.png', (string) ob_get_clean())], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    ($this->add)('message', ['name' => 'Dr. Rahim'])->assertUnprocessable()->assertJsonValidationErrors('data.body.bn');
    ($this->add)('message', ['image_media_id' => $photo, 'name' => 'ড. রহিম', 'role' => ['bn' => 'অধ্যক্ষ'], 'body' => ['bn' => "প্রিয় শিক্ষার্থী,\nস্বাগতম।"]])->assertCreated();

    ($this->add)('calendar', ['items' => [['from' => '2026-12-10', 'to' => '2026-12-01', 'label' => ['bn' => 'ভুল']]]])->assertUnprocessable()->assertJsonValidationErrors('data.items.0.to');
    ($this->add)('calendar', ['items' => [['from' => '2026-12-10', 'label' => ['en' => 'Only English']]]])->assertUnprocessable()->assertJsonValidationErrors('data.items.0.label.bn');
    ($this->add)('calendar', ['title' => ['bn' => 'একাডেমিক ক্যালেন্ডার'], 'items' => [
        ['from' => '2026-12-16', 'label' => ['bn' => 'বিজয় দিবস']],
        ['from' => '2026-10-01', 'to' => '2026-10-05', 'label' => ['bn' => 'অর্ধবার্ষিক পরীক্ষা']],
        ['from' => '2027-01-01', 'label' => ['bn' => 'নতুন শিক্ষাবর্ষ']],
    ]])->assertCreated();
    ($this->publish)();

    $html = $this->get('/s/abc-madrasa')->assertOk()->getContent();
    expect($html)->toContain('<blockquote>প্রিয় শিক্ষার্থী,'."\n".'স্বাগতম।</blockquote>')->toContain('<b>ড. রহিম</b><span>অধ্যক্ষ</span>')
        ->toContain('/media/'.$photo.'/small')
        ->toContain('অক্টোবর ২০২৬')->toContain('ডিসেম্বর ২০২৬')->toContain('২০২৭</h3>')
        // The exam is past (15 October); the holiday is not.
        ->and(substr_count($html, 'class="event past"'))->toBe(1)
        ->and(strpos($html, 'অর্ধবার্ষিক পরীক্ষা'))->toBeLessThan(strpos($html, 'বিজয় দিবস'));
});

it('writes the line above every page as given, escaped, in its own direction', function () {
    ($this->start)('campus')->assertCreated();
    $site = $this->asToken($this->token)->getJson(($this->api)())->json('data');
    $this->asToken($this->token)->patchJson(($this->api)(), ['base_version' => $site['version'], 'motto' => 'بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ <b>'])->assertOk()
        ->assertJsonPath('data.motto', 'بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ <b>');
    $this->asToken($this->token)->patchJson(($this->api)(), ['base_version' => $site['version'] + 1, 'motto' => str_repeat('x', 161)])->assertUnprocessable()->assertJsonValidationErrors('motto');
    ($this->publish)();

    $html = $this->get('/s/abc-madrasa/notices')->assertOk()->getContent();
    expect($html)->toContain('<body class="tpl-campus">')
        ->toContain('<div class="motto" dir="auto">بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ &lt;b&gt;</div>');
});

it('lets a level above turn the new templates off', function () {
    trustedOrgRule($this->w->c1, 'edu_website.templates', ['school', 'minimal']);
    ($this->start)('madrasa')->assertUnprocessable()->assertJsonPath('code', 'template_closed');
    ($this->start)('school')->assertCreated();
    $site = $this->asToken($this->token)->getJson(($this->api)())->json('data');
    $this->asToken($this->token)->patchJson(($this->api)(), ['base_version' => $site['version'], 'template' => 'campus'])->assertUnprocessable()->assertJsonPath('code', 'template_closed');
});
