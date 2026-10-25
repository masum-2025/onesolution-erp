<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Tenancy\Enums\MembershipType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\RateLimiter;
use Modules\EduWebsite\Models\Enquiry;
use Modules\EduWebsite\Models\Site;
use Modules\EduWebsite\Services\Enquiries;

/*
 * WEB-3b: admission enquiries from a school's public website. The form
 * works on kept pages (a signed token of the site, at least seconds old),
 * robots are told it went and nothing is kept, wrong details show the
 * page again with what to fix, a number and a visitor may send only a
 * few. Names and numbers are kept encrypted, for the people who handle
 * enquiries only, and removed after the company's keeping time.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 04:00:00', 'UTC'));
    URL::forceRootUrl(config('app.url'));
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'education', 'Test setup');
        toggles()->enable($group, 'edu_website', 'Test setup');
    }
    $this->token = orgToken(createMember($this->w->c1), $this->w->c1);
    $this->asToken($this->token)->postJson("/api/organizations/{$this->w->c1->id}/education/presets/bd_school/apply")->assertOk();
    $this->api = fn (string $path = '', $org = null) => '/api/organizations/'.($org ?? $this->w->c1)->id.'/website'.($path === '' ? '' : "/{$path}");
    $this->asToken($this->token)->postJson(($this->api)(), [
        'slug' => 'abc-school', 'title' => ['en' => 'ABC High School'], 'template' => 'school', 'default_locale' => 'en',
    ])->assertCreated();
    $home = $this->asToken($this->token)->getJson(($this->api)('pages'))->json('data.0');
    $this->asToken($this->token)->postJson(($this->api)("pages/{$home['id']}/blocks"), ['type' => 'enquiry', 'data' => [
        'title' => ['en' => 'Ask about admission'], 'thanks' => ['en' => 'We will call you within two days.'], 'ask_level' => true,
    ]])->assertCreated();
    $site = $this->asToken($this->token)->getJson(($this->api)())->json('data');
    $this->asToken($this->token)->postJson(($this->api)('publish'), ['base_version' => $site['version']])->assertOk();

    // The form as a visitor gets it, then sent some seconds later.
    $this->form = function (string $url = '/s/abc-school') {
        $html = $this->get($url)->assertOk()->getContent();
        preg_match('/name="t" value="([^"]+)"/', $html, $token);
        preg_match_all('/<option value="([0-9a-z]{26})"/', $html, $levels);

        return ['t' => $token[1] ?? '', 'level' => $levels[1][0] ?? null];
    };
    $this->send = function (array $data = [], ?string $url = null, ?string $token = null, int $after = 5) {
        $form = ($this->form)();
        $this->travel($after)->seconds();

        return $this->post($url ?? '/s/abc-school/enquiry', [
            't' => $token ?? $form['t'], 'back' => '', 'lang' => 'en', 'website' => '',
            'student_name' => 'Rafi Ahmed', 'guardian_name' => 'Karim Ahmed', 'phone' => '01711-000000', 'level_id' => $form['level'], 'message' => 'Is there a bus?',
            ...$data,
        ]);
    };
});

it('takes an enquiry from the published page, keeps it encrypted and thanks the visitor', function () {
    $form = ($this->form)();
    expect($form['t'])->toMatch('/^\d+\.[0-9a-f]{64}$/')->and($form['level'])->not->toBeNull();

    ($this->send)()->assertStatus(303)->assertRedirect('/s/abc-school/?lang=en&enquiry=sent#enquiry');
    $enquiry = Enquiry::query()->withoutGlobalScopes()->sole();
    expect($enquiry->student_name)->toBe('Rafi Ahmed')->and($enquiry->phone)->toBe('+8801711000000')
        ->and($enquiry->status)->toBe('new')->and($enquiry->level_name['en'] ?? null)->not->toBeNull();
    $raw = DB::connection($enquiry->getConnectionName())->table('web_enquiries')->first();
    expect($raw->phone)->not->toContain('1711000000')->and($raw->student_name)->not->toContain('Rafi');

    $this->get('/s/abc-school/?lang=en&enquiry=sent')->assertOk()->assertSee('We will call you within two days.')->assertHeader('Cache-Control', 'no-store, private');

    // Bangla digits are digits too.
    ($this->send)(['phone' => '০১৮১১-২২২৩৩৩'])->assertStatus(303);
    expect(Enquiry::query()->withoutGlobalScopes()->latest('created_at')->first()->phone)->toBe('+8801811222333');
});

it('tells robots it went and keeps nothing', function () {
    ($this->send)(['website' => 'http://spam.example'])->assertStatus(303);
    // A form sent the moment it was made.
    $site = Site::query()->withoutGlobalScopes()->sole();
    ($this->send)([], null, app(Enquiries::class)->token($site, now()->getTimestamp() + 5), 5)->assertStatus(303);
    expect(Enquiry::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('shows the page again with what to fix', function () {
    ($this->send)([], null, '123.abc')->assertStatus(422)->assertSee('This form is old.');
    ($this->send)([], null, (string) (now()->getTimestamp() - 2 * 86400).'.'.str_repeat('a', 64))->assertStatus(422);
    ($this->send)(['phone' => '12'])->assertStatus(422)->assertSee('Write a mobile number')->assertSee('value="Rafi Ahmed"', false);
    ($this->send)(['student_name' => ''])->assertStatus(422)->assertSee('Please fill this in.');
    ($this->send)(['level_id' => str_repeat('a', 26)])->assertStatus(422)->assertSee('Choose a class from the list.');
    // A fresh hour for this visitor (the route's limit and the controller's).
    RateLimiter::clear('edu-website-enquiry:127.0.0.1');
    RateLimiter::clear(md5('edu-website-enquiry'.'edu-website-enquiry-route:127.0.0.1'));
    ($this->send)(['student_name' => '<script>alert(1)</script>', 'phone' => '1'])->assertStatus(422)
        ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    expect(Enquiry::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('lets a number and a visitor send only a few', function () {
    foreach (range(1, 3) as $n) {
        ($this->send)(['student_name' => "Child {$n}"])->assertStatus(303);
    }
    ($this->send)()->assertStatus(429)->assertSee('This number has asked several times today.');
    // Five an hour from one visitor, whatever the number.
    ($this->send)(['phone' => '01911-000000'])->assertStatus(303);
    ($this->send)(['phone' => '01811-000000'])->assertStatus(429)->assertContent('');
    expect(Enquiry::query()->withoutGlobalScopes()->count())->toBe(4);
});

it('takes enquiries on the site\'s own host, and only on published sites', function () {
    $form = ($this->form)('http://abc-school.sites.localhost/');
    $this->travel(5)->seconds();
    $this->post('http://abc-school.sites.localhost/enquiry', ['t' => $form['t'], 'back' => '', 'lang' => 'en', 'student_name' => 'Mina', 'guardian_name' => 'Rina', 'phone' => '01711000001'])
        ->assertStatus(303)->assertRedirect('/?lang=en&enquiry=sent#enquiry');
    $this->post('http://abc-school.sites.localhost/notices')->assertStatus(405);

    $site = $this->asToken($this->token)->getJson(($this->api)())->json('data');
    $this->asToken($this->token)->postJson(($this->api)('offline'), ['base_version' => $site['version']])->assertOk();
    $this->post('/s/abc-school/enquiry', ['t' => $form['t'], 'student_name' => 'X', 'guardian_name' => 'Y', 'phone' => '01711000002'])->assertNotFound();
    expect(Enquiry::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('lists enquiries for the people who handle them, with their status, note and a CSV', function () {
    ($this->send)()->assertStatus(303);
    ($this->send)(['student_name' => '=HYPERLINK("http://evil")', 'phone' => '01911-000000'])->assertStatus(303);

    $list = $this->asToken($this->token)->getJson(($this->api)('enquiries'))->assertOk();
    expect($list->json('meta.counts'))->toBe(['new' => 2, 'contacted' => 0, 'admitted' => 0, 'closed' => 0])
        ->and(collect($list->json('data'))->pluck('phone')->all())->toContain('+8801711000000');
    $this->asToken($this->token)->getJson(($this->api)('enquiries').'?q=rafi')->assertOk()->assertJsonCount(1, 'data');
    $this->asToken($this->token)->getJson(($this->api)('enquiries').'?q=01911')->assertOk()->assertJsonCount(1, 'data');

    $one = collect($list->json('data'))->firstWhere('student_name', 'Rafi Ahmed');
    $this->asToken($this->token)->patchJson(($this->api)("enquiries/{$one['id']}"), ['base_version' => $one['version'], 'status' => 'contacted', 'note' => 'Called; visiting Sunday.'])
        ->assertOk()->assertJsonPath('data.status', 'contacted')->assertJsonPath('data.note', 'Called; visiting Sunday.');
    $this->asToken($this->token)->patchJson(($this->api)("enquiries/{$one['id']}"), ['base_version' => $one['version'], 'status' => 'closed'])->assertConflict();
    $this->asToken($this->token)->patchJson(($this->api)("enquiries/{$one['id']}"), ['base_version' => 2, 'status' => 'lost'])->assertUnprocessable()->assertJsonValidationErrors('status');

    $csv = $this->asToken($this->token)->get(($this->api)('enquiries/export'))->assertOk()->streamedContent();
    expect($csv)->toContain('Rafi Ahmed')->toContain("'=HYPERLINK")->not->toContain(',=HYPERLINK')
        ->and(AuditLog::query()->where('action', 'edu_website.enquiries_exported')->count())->toBe(1);

    // The bell of those who handle them.
    expect(collect($this->asToken($this->token)->getJson('/api/attention')->json('data'))->firstWhere('key', 'edu_website.enquiries_waiting')['count'] ?? null)->toBe(1);
});

it('keeps enquiries to the people allowed, the company and the module', function () {
    ($this->send)()->assertStatus(303);
    $id = Enquiry::query()->withoutGlobalScopes()->value('id');
    $editor = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['edu_website.view', 'edu_website.manage'], 'Editor')), $this->w->c1);
    $this->asToken($editor)->getJson(($this->api)('enquiries'))->assertForbidden();
    $this->asToken($editor)->get(($this->api)('enquiries/export'))->assertForbidden();

    $sister = orgToken(createMember($this->w->c2, MembershipType::Owner), $this->w->c2);
    $this->asToken($sister)->getJson(($this->api)('enquiries', $this->w->c2))->assertOk()->assertJsonCount(0, 'data');
    $this->asToken($sister)->patchJson(($this->api)("enquiries/{$id}", $this->w->c2), ['base_version' => 1, 'status' => 'closed'])->assertNotFound();

    toggles()->disable($this->w->g1, 'edu_website', 'Not now');
    $this->asToken($this->token)->getJson(($this->api)('enquiries'))->assertForbidden();
});

it('removes enquiries after the company\'s keeping time', function () {
    ($this->send)()->assertStatus(303);
    $this->travel(181)->days();
    ($this->send)(['phone' => '01911-000000'])->assertStatus(303);

    $this->artisan('edu-website:prune-enquiries')->assertSuccessful();
    expect(Enquiry::query()->withoutGlobalScopes()->count())->toBe(1);

    trustedOrgRule($this->w->c1, 'edu_website.enquiry_retention_days', 30);
    $this->travel(31)->days();
    $this->artisan('edu-website:prune-enquiries')->assertSuccessful();
    expect(Enquiry::query()->withoutGlobalScopes()->count())->toBe(0);
});
