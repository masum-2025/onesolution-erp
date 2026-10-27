<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Notifications\Models\NotificationDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Modules\Recruitment\Models\Offer;

/*
 * REC-3: the company's offer letter (the rule's wording until it writes
 * its own; unknown placeholders refused), job offers written by HR,
 * approved by someone else who may hire when the rule asks, sent to the
 * candidate with a link to accept or decline, expiring after their day,
 * withdrawn. Company C1; today is Wednesday 28 October 2026.
 */

beforeEach(function () {
    Queue::fake(); // keep messages queued: a sent message's values are wiped after delivery
    $this->travelTo(CarbonImmutable::parse('2026-10-28 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        setPlan($group, 'business');
        toggles()->enable($group, 'recruitment', 'Test setup');
    }
    $c1 = $this->w->c1;
    $this->hrUser = staffWithRoles($c1, makeRole($c1, ['hrm.view', 'hrm.manage', 'recruitment.view', 'recruitment.manage', 'recruitment.hire'], 'HR'));
    $this->hr = orgToken($this->hrUser, $c1);
    $this->approverUser = staffWithRoles($c1, makeRole($c1, ['recruitment.view', 'recruitment.hire'], 'Director'));
    $this->approver = orgToken($this->approverUser, $c1);
    $this->viewer = orgToken(staffWithRoles($c1, makeRole($c1, ['recruitment.view'], 'Viewer')), $c1);

    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $c1)->id."/recruitment/{$path}";
    $job = $this->asToken($this->hr)->postJson(($this->api)('openings'), ['title' => ['en' => 'Accounts officer', 'bn' => 'হিসাব কর্মকর্তা']])->assertCreated()->json('data');
    $this->job = $this->asToken($this->hr)->postJson(($this->api)("openings/{$job['id']}/open"), ['base_version' => $job['version']])->assertOk()->json('data');
    $this->asToken($this->hr)->putJson(($this->api)('careers'), ['slug' => 'c1-jobs'])->assertOk();
    $this->application = $this->asToken($this->hr)->postJson(($this->api)('applications'), [
        'opening_id' => $this->job['id'], 'name' => 'Karim Uddin', 'email' => 'karim@example.test', 'source' => 'referral',
    ])->assertCreated()->json('data');

    $this->write = fn (array $data = [], ?string $token = null) => $this->asToken($token ?? $this->hr)->postJson(($this->api)("applications/{$this->application['id']}/offers"), [
        'salary_minor' => 3500000, 'start_on' => '2026-11-15', 'respond_by' => '2026-11-04', ...$data,
    ]);
    $this->step = fn (array $offer, string $step, string $token, array $data = []) => $this->asToken($token)
        ->postJson(($this->api)("offers/{$offer['id']}/{$step}"), ['base_version' => $offer['version'], ...$data]);
    // The link in the candidate's mail (its token is in the message values until it is sent).
    $this->link = function () {
        $link = parse_url(NotificationDelivery::query()->where('notification_key', 'recruitment.offer_sent')->latest('id')->first()->data['link']);

        // The candidate's language is in the link (the company's for people added by hand); pages here are read in English.
        expect($link['query'])->toBe('lang=bn');

        return $link['path'].'?lang=en';
    };
});

it('uses the rule\'s letter until the company writes its own, refusing unknown placeholders', function () {
    $letter = $this->asToken($this->viewer)->getJson(($this->api)('letter'))->assertOk()->json('data');
    expect($letter)->toMatchArray(['own' => false, 'version' => 0, 'can_manage' => false])
        ->and($letter['body']['en'])->toContain('{candidate.name}')
        ->and($letter['placeholders'])->toContain('salary', 'start_date')
        ->and($letter['sample'])->toContain('Sadia Rahman')->not->toContain('{');

    $this->asToken($this->viewer)->putJson(($this->api)('letter'), ['base_version' => 0, 'body' => ['en' => str_repeat('Dear {candidate.name}. ', 3)]])->assertForbidden();
    $this->asToken($this->hr)->putJson(($this->api)('letter'), ['base_version' => 0, 'body' => ['en' => 'Dear {candidte.name}, welcome to {company.name}.', 'bn' => 'প্রিয় {name}, স্বাগতম আমাদের প্রতিষ্ঠানে।']])
        ->assertUnprocessable()->assertJsonValidationErrors(['body.en', 'body.bn']);
    $saved = $this->asToken($this->hr)->putJson(($this->api)('letter'), ['base_version' => 0, 'body' => ['en' => "Dear {candidate.name},\nWe offer you {job.title} at {salary} from {start_date}."]])
        ->assertOk()->json('data');
    expect($saved)->toMatchArray(['own' => true, 'version' => 1]);
    $this->asToken($this->hr)->putJson(($this->api)('letter'), ['base_version' => 0, 'body' => ['en' => 'Dear {candidate.name}, we offer you the job.']])
        ->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    expect(AuditLog::query()->where('action', 'recruitment.letter_changed')->count())->toBe(1);
});

it('is written by HR, approved by someone else, sent and accepted by the candidate', function () {
    ($this->write)(['respond_by' => '2026-10-20'])->assertUnprocessable()->assertJsonValidationErrors('respond_by');
    ($this->write)(['start_on' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('start_on');
    ($this->write)([], $this->viewer)->assertForbidden();
    $offer = ($this->write)()->assertCreated()->json('data');
    expect($offer)->toMatchArray(['status' => 'draft', 'salary_minor' => 3500000, 'currency' => 'BDT'])->and($offer['can']['edit'])->toBeTrue();
    ($this->write)()->assertStatus(409)->assertJsonPath('code', 'offer_exists');

    // Sending asks for approval (the rule); the writer may not approve.
    $offer = ($this->step)($offer, 'send', $this->hr)->assertOk()->json('data');
    expect($offer['status'])->toBe('pending_approval')
        ->and(NotificationDelivery::query()->where('notification_key', 'recruitment.offer_to_approve')->pluck('user_id')->all())->toContain($this->approverUser->id)->not->toContain($this->hrUser->id)
        ->and(json_encode(NotificationDelivery::query()->where('notification_key', 'recruitment.offer_to_approve')->first()->data))->not->toContain('Karim')->not->toContain('35');
    ($this->step)($offer, 'approve', $this->hr)->assertForbidden()->assertJsonPath('code', 'own_offer');
    ($this->step)($offer, 'approve', $this->viewer)->assertForbidden();
    ($this->step)($offer, 'return', $this->approver)->assertUnprocessable()->assertJsonValidationErrors('note');
    $offer = ($this->step)($offer, 'return', $this->approver, ['note' => 'Start on the 1st of the month'])->assertOk()->json('data');
    expect($offer)->toMatchArray(['status' => 'draft', 'note' => 'Start on the 1st of the month']);

    $offer = $this->asToken($this->hr)->patchJson(($this->api)("offers/{$offer['id']}"), ['base_version' => $offer['version'], 'salary_minor' => 3600000, 'start_on' => '2026-12-01', 'respond_by' => '2026-11-04'])
        ->assertOk()->json('data');
    $offer = ($this->step)(($this->step)($offer, 'send', $this->hr)->json('data'), 'approve', $this->approver)->assertOk()->json('data');
    expect($offer)->toMatchArray(['status' => 'sent', 'approved_by' => $this->approverUser->name]);
    $this->asToken($this->hr)->patchJson(($this->api)("offers/{$offer['id']}"), ['base_version' => $offer['version'], 'salary_minor' => 1, 'start_on' => '2026-12-01', 'respond_by' => '2026-11-04'])
        ->assertStatus(409);

    // The letter kept as sent, filled in.
    $letter = $this->asToken($this->hr)->getJson(($this->api)("offers/{$offer['id']}"))->assertOk()->json('data.letter');
    // In the candidate's language (Bangla here): the company's wording, Bangla digits.
    expect($letter)->toContain('Karim Uddin')->toContain('হিসাব কর্মকর্তা')->toContain('৩৬,০০০')->toContain('২০২৬')->not->toContain('{');

    // The candidate: the letter and the buttons; opening the link answers nothing.
    $link = ($this->link)();
    expect($link)->toStartWith('/careers/c1-jobs/offer/');
    $page = $this->get($link)->assertOk()->assertSee('Karim Uddin')->assertSee('I accept');
    expect($page->headers->get('Content-Security-Policy'))->toContain("script-src 'none'")
        ->and(Offer::query()->withoutGlobalScopes()->sole()->status)->toBe('sent');
    $this->post($link, ['answer' => 'maybe'])->assertNotFound();
    $this->post($link, ['answer' => 'accept'])->assertOk()->assertSee('You accepted the offer');
    $this->post($link, ['answer' => 'decline'])->assertOk()->assertSee('You accepted the offer');
    expect(Offer::query()->withoutGlobalScopes()->sole()->status)->toBe('accepted')
        ->and(NotificationDelivery::query()->where('notification_key', 'recruitment.offer_answered')->pluck('user_id')->all())->toContain($this->hrUser->id, $this->approverUser->id);

    $detail = $this->asToken($this->hr)->getJson(($this->api)("applications/{$this->application['id']}"))->assertOk()->json('data');
    expect($detail['offers'][0])->toMatchArray(['status' => 'accepted', 'start_on' => '2026-12-01'])
        ->and(AuditLog::query()->whereIn('action', ['recruitment.offer_written', 'recruitment.offer_send', 'recruitment.offer_return', 'recruitment.offer_approve', 'recruitment.offer_accepted'])->count())->toBe(6);
});

it('goes straight to the candidate when the rule needs no approval, and expires after its day', function () {
    trustedOrgRule($this->w->c1, 'recruitment.offer_needs_approval', false);
    $offer = ($this->step)(($this->write)()->json('data'), 'send', $this->hr)->assertOk()->json('data');
    expect($offer['status'])->toBe('sent');
    $link = ($this->link)();

    $this->travelTo(CarbonImmutable::parse('2026-11-05 04:00:00', 'UTC'));
    $this->get($link)->assertOk()->assertSee('This offer is no longer open')->assertDontSee('I accept');
    $this->post($link, ['answer' => 'accept'])->assertOk()->assertSee('This offer is no longer open');
    Artisan::call('recruitment:expire-offers');
    expect(Offer::query()->withoutGlobalScopes()->sole()->status)->toBe('expired');
    $this->get($link)->assertNotFound();
});

it('needs a careers address to send, and a withdrawn offer\'s link stops working', function () {
    trustedOrgRule($this->w->c1, 'recruitment.offer_needs_approval', false);
    Modules\Recruitment\Models\Application::query()->withoutGlobalScopes()->update(['updated_at' => now()]);
    App\Platform\Sites\SiteAddress::query()->where('module', 'recruitment')->delete();
    app(App\Platform\Sites\SiteResolver::class)->forget('c1-jobs');
    $offer = ($this->write)()->json('data');
    ($this->step)($offer, 'send', $this->hr)->assertUnprocessable()->assertJsonPath('code', 'no_careers_address');

    $this->asToken($this->hr)->putJson(($this->api)('careers'), ['slug' => 'c1-jobs'])->assertOk();
    $offer = ($this->step)($offer, 'send', $this->hr)->assertOk()->json('data');
    $link = ($this->link)();
    ($this->step)($offer, 'withdraw', $this->hr, ['note' => 'Budget cut'])->assertOk()->assertJsonPath('data.status', 'withdrawn');
    $this->get($link)->assertNotFound();
    // A new offer may follow.
    ($this->write)()->assertCreated();
});

it('keeps offers to those who may see the application\'s money, and to the company', function () {
    $offer = ($this->write)()->json('data');
    $interviewerUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['recruitment.interview'], 'Interviewer'));
    $this->asToken($this->hr)->postJson(($this->api)("applications/{$this->application['id']}/interviews"), [
        'starts_at' => '2026-10-30T10:00', 'minutes' => 30, 'mode' => 'phone', 'interviewer_ids' => [$interviewerUser->id],
    ])->assertCreated();
    $interviewer = orgToken($interviewerUser, $this->w->c1);
    expect($this->asToken($interviewer)->getJson(($this->api)("applications/{$this->application['id']}"))->assertOk()->json('data.offers'))->toBe([]);
    $this->asToken($interviewer)->getJson(($this->api)("offers/{$offer['id']}"))->assertForbidden();

    $c2 = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['recruitment.view', 'recruitment.manage', 'recruitment.hire'], 'C2 HR')), $this->w->c2);
    $this->asToken($c2)->getJson(($this->api)("offers/{$offer['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($c2)->postJson(($this->api)("offers/{$offer['id']}/send", $this->w->c2), ['base_version' => $offer['version']])->assertNotFound();
    $this->get('/careers/c1-jobs/offer/'.str_repeat('a', 48))->assertNotFound();
});
