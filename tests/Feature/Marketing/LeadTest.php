<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Marketing\Events\LeadReceived;
use App\Platform\Marketing\Models\Lead;
use App\Platform\Marketing\Models\Page;
use App\Platform\Marketing\Models\Site;
use App\Platform\Marketing\Services\Leads;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

/*
 * MKT-3b: the contact form of a company website. A visitor's message is
 * kept only when it passes the bot checks, and only the partner's owners
 * and sales staff read it; partners never see each other's.
 */

beforeEach(function () {
    config(['marketing.hosts' => 'marketing.test', 'marketing.url' => '']);
    RateLimiter::clear('marketing:127.0.0.1');
    $this->w = tenancyWorld();
    $this->house = Partner::query()->where('is_house', true)->first() ?? Partner::factory()->house()->create(['name' => 'One Solutions']);
    $this->ownerUser = createPartnerStaff($this->house, PartnerUserRole::Owner);
    $this->owner = partnerToken($this->ownerUser, $this->house);
    $this->asToken($this->owner)->postJson('/api/partner/website')->assertCreated();
    $this->site = Site::query()->where('partner_id', $this->house->id)->firstOrFail();
    $home = Page::query()->where('site_id', $this->site->id)->where('is_home', true)->firstOrFail();
    $this->block = $this->asToken($this->owner)->postJson("/api/partner/website/pages/{$home->id}/blocks", [
        'type' => 'contact_form', 'data' => [
            'title' => ['en' => 'Talk to us', 'bn' => 'কথা বলুন'], 'ask_email' => true, 'ask_company' => false, 'ask_message' => true,
            'thanks' => ['en' => 'We call back within a day.'], 'show_contact' => true,
        ],
    ])->assertCreated()->json('data.id');
    $this->asToken($this->owner)->postJson('/api/partner/website/publish')->assertOk();
    $this->site->refresh();
});

function sendLead(object $test, array $fields = [], ?string $stamp = null)
{
    $response = $test->call('POST', 'http://marketing.test/contact', [
        'block' => $test->block, 'page' => '', 'lang' => 'en',
        'stamp' => $stamp ?? Leads::stamp($test->site, time() - 30),
        'name' => 'Karim Ahmed', 'phone' => '01712 345678', 'email' => 'karim@example.com', 'message' => 'We need payroll for 80 staff.',
        ...$fields,
    ], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
    URL::forceRootUrl(config('app.url'));

    return $response;
}

it('shows the form on the site, keeps a message and tells the partner\'s staff', function () {
    $sales = createPartnerStaff($this->house, PartnerUserRole::Sales);
    $page = $this->get('http://marketing.test/')->assertOk()
        ->assertSee('action="/contact"', false)->assertSee('name="stamp"', false)->assertSee('name="website"', false);
    expect($page->getContent())->not->toContain('name="company"');
    URL::forceRootUrl(config('app.url'));

    sendLead($this)->assertOk()->assertSee('We call back within a day.')->assertDontSee('name="stamp"', false);

    $lead = Lead::query()->sole();
    expect($lead)->toMatchArray(['name' => 'Karim Ahmed', 'phone' => '01712 345678', 'email' => 'karim@example.com', 'company' => null, 'status' => 'new', 'locale' => 'en'])
        ->and(NotificationDelivery::query()->where('user_id', $this->ownerUser->id)->exists())->toBeTrue()
        ->and(NotificationDelivery::query()->where('user_id', $sales->id)->exists())->toBeTrue();

    // The same phone again within minutes is the same message.
    sendLead($this)->assertOk();
    expect(Lead::query()->count())->toBe(1);
});

it('drops bots, refuses stale or forged stamps, and tells the visitor what to fix', function () {
    Event::fake([LeadReceived::class]);

    sendLead($this, ['website' => 'http://spam.example'])->assertOk()->assertSee(__('marketing.public.form.sent_title'));
    sendLead($this, [], Leads::stamp($this->site, time()))->assertUnprocessable()->assertSee(__('marketing.public.form.expired'));
    sendLead($this, [], Leads::stamp($this->site, time() - 3 * 86400))->assertUnprocessable();
    sendLead($this, [], (time() - 30).'.forged')->assertUnprocessable();
    sendLead($this, ['phone' => 'call me', 'email' => 'not-an-email'])->assertUnprocessable()
        ->assertSee(__('marketing.public.form.errors.phone'))->assertSee(__('marketing.public.form.errors.email'))
        ->assertSee('value="Karim Ahmed"', false)->assertSee('aria-invalid="true"', false);
    // An answer that is not a text, or a field the form does not ask, changes nothing.
    sendLead($this, ['name' => ['x' => 'y']])->assertUnprocessable();
    sendLead($this, ['company' => 'Sneaky Ltd', 'phone' => '01800 000000'])->assertOk();

    expect(Lead::query()->count())->toBe(1)->and(Lead::query()->value('company'))->toBeNull();
    Event::assertDispatchedTimes(LeadReceived::class, 1);

    // Only a plain form post to a published form: no JSON, no unknown section, no other method.
    $this->postJson('http://marketing.test/contact', ['name' => 'x'])->assertStatus(415);
    sendLead($this, ['block' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'])->assertNotFound();
    $this->call('PUT', 'http://marketing.test/contact')->assertStatus(405);
});

it('limits how many messages one visitor sends an hour', function () {
    config(['marketing.leads_per_hour' => 2]);
    sendLead($this, ['phone' => '01700 000001'])->assertOk();
    sendLead($this, ['phone' => '01700 000002'])->assertOk();
    sendLead($this, ['phone' => '01700 000003'])->assertStatus(429)->assertSee(__('marketing.public.form.busy'));

    expect(Lead::query()->count())->toBe(2);
});

it('does not send from a preview', function () {
    $url = $this->asToken($this->owner)->postJson('/api/partner/website/preview-link')->assertOk()->json('data.url');
    $this->get($url)->assertOk()->assertSee(__('marketing.public.form.preview'))->assertSee('action="#"', false)->assertSee('disabled', false);
});

it('lets owners and sales staff follow up, and only owners remove a message', function () {
    sendLead($this);
    $lead = Lead::query()->sole();
    $sales = partnerToken(createPartnerStaff($this->house, PartnerUserRole::Sales), $this->house);
    $support = partnerToken(createPartnerStaff($this->house, PartnerUserRole::Support), $this->house);

    $this->asToken($support)->getJson('/api/partner/website/leads')->assertForbidden();
    $this->asToken($sales)->getJson('/api/partner/website/leads')->assertOk()
        ->assertJsonPath('data.0.phone', '01712 345678')->assertJsonPath('meta.counts.new', 1)->assertJsonPath('meta.can_remove', false);
    $this->asToken($sales)->getJson('/api/partner/website/leads?q=karim&status=new')->assertOk()->assertJsonCount(1, 'data');
    $this->asToken($sales)->getJson('/api/partner/website/leads?status=won')->assertOk()->assertJsonCount(0, 'data');

    $this->asToken($sales)->patchJson("/api/partner/website/leads/{$lead->id}", ['base_version' => 1, 'status' => 'contacted', 'note' => 'Demo on Sunday'])->assertOk()
        ->assertJsonPath('data.status', 'contacted')->assertJsonPath('data.version', 2);
    $this->asToken($sales)->patchJson("/api/partner/website/leads/{$lead->id}", ['base_version' => 1, 'status' => 'won'])->assertStatus(409);
    $this->asToken($sales)->patchJson("/api/partner/website/leads/{$lead->id}", ['base_version' => 2, 'status' => 'lost'])->assertJsonValidationErrors('status');
    $this->asToken($sales)->patchJson("/api/partner/website/leads/{$lead->id}", ['base_version' => 2, 'phone' => '1'])->assertJsonValidationErrors('phone');
    $this->asToken($sales)->deleteJson("/api/partner/website/leads/{$lead->id}")->assertForbidden();

    $this->asToken($this->owner)->deleteJson("/api/partner/website/leads/{$lead->id}")->assertOk();
    expect(Lead::query()->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'marketing.lead_removed')->sole()->old_values ?? [])->not->toHaveKey('phone');
});

it('keeps partners\' messages apart', function () {
    sendLead($this);
    $lead = Lead::query()->sole();

    $tokenA = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
    $this->asToken($tokenA)->postJson('/api/partner/website')->assertCreated();

    $this->asToken($tokenA)->getJson('/api/partner/website/leads')->assertOk()->assertJsonCount(0, 'data');
    $this->asToken($tokenA)->patchJson("/api/partner/website/leads/{$lead->id}", ['base_version' => 1, 'status' => 'closed'])->assertNotFound();
    $this->asToken($tokenA)->deleteJson("/api/partner/website/leads/{$lead->id}")->assertNotFound();
    expect($lead->refresh()->status)->toBe('new');
});
