<?php

use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Notifications\NotificationCatalog;
use App\Platform\Rules\RuleTargets;
use App\Platform\Tenancy\Enums\MembershipType;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Hrm\Models\DocumentAlert;

/*
 * HRM-3b: reporting lines stay a tree, the org chart opens level by level,
 * and HR hears about employee documents before (and when) they expire.
 */

beforeEach(function () {
    Storage::fake('local');
    // 15 October 2026 in Dhaka; before any sign-in token is made.
    $this->travelTo(CarbonImmutable::parse('2026-10-15 06:00', 'UTC'));
    $this->w = hrmWorld($this);
    $this->hr = "/api/organizations/{$this->w->c1->id}/hrm";
});

function hireNamed(object $test, string $name, ?string $manager = null, string $phone = '+8801711000000', $organization = null): string
{
    return hireVia($test, $test->w->token, $organization ?? $test->w->c1, array_filter(['full_name' => $name, 'manager_id' => $manager, 'phone' => $phone]))
        ->assertCreated()->json('data.id');
}

function documentFor(object $test, string $employee, string $expires, string $title = 'Work permit'): string
{
    return $test->asToken($test->w->token)->post("{$test->hr}/employees/{$employee}/documents", [
        'file' => UploadedFile::fake()->create('permit.pdf', 50, 'application/pdf'),
        'type' => 'contract',
        'title' => $title,
        'expires_on' => $expires,
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
}

// ── Reporting lines ──

it('refuses a manager change that would make a loop, and says how', function () {
    $anika = hireNamed($this, 'Anika Rahman', phone: '+8801711000001');
    $bilal = hireNamed($this, 'Bilal Hossain', $anika, '+8801711000002');
    $chandni = hireNamed($this, 'Chandni Akter', $bilal, '+8801711000003');

    $this->asToken($this->w->token)->patchJson("{$this->hr}/employees/{$anika}", ['base_version' => 1, 'manager_id' => $chandni])
        ->assertUnprocessable()
        ->assertJsonPath('errors.manager_id.0', 'This would make a loop: Anika Rahman → Chandni Akter → Bilal Hossain → Anika Rahman. Choose a manager who does not report to this person.');

    // Moving someone under a person who does not report to them is fine.
    $this->asToken($this->w->token)->patchJson("{$this->hr}/employees/{$chandni}", ['base_version' => 1, 'manager_id' => $anika])->assertOk();
});

it('keeps reporting lines within the rule\'s length', function () {
    orgRule($this->w->c1, 'hrm.max_reporting_depth', 2);
    $anika = hireNamed($this, 'Anika Rahman', phone: '+8801711000001');
    $bilal = hireNamed($this, 'Bilal Hossain', $anika, '+8801711000002');
    $chandni = hireNamed($this, 'Chandni Akter', $bilal, '+8801711000003');

    hireVia($this, $this->w->token, $this->w->c1, ['full_name' => 'Dipu Saha', 'manager_id' => $chandni, 'phone' => '+8801711000004'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.manager_id.0', 'The reporting line above this manager is already 2 people long. Choose a manager higher up.');
});

// ── Org chart ──

it('opens the org chart one level at a time, employed people only', function () {
    $anika = hireNamed($this, 'Anika Rahman', phone: '+8801711000001');
    $bilal = hireNamed($this, 'Bilal Hossain', $anika, '+8801711000002');
    hireNamed($this, 'Chandni Akter', $anika, '+8801711000003');
    hireNamed($this, 'Esha Branch', $bilal, '+8801711000005', $this->w->b1);

    $this->asToken($this->w->token)->getJson("{$this->hr}/org-chart")->assertOk()
        ->assertJsonPath('data.*.full_name', ['Anika Rahman'])
        ->assertJsonPath('data.0.reports_count', 2)
        ->assertJsonPath('meta', ['total' => 1, 'shown' => 1]);

    $this->asToken($this->w->token)->getJson("{$this->hr}/org-chart?manager_id={$anika}")->assertOk()
        ->assertJsonPath('manager.full_name', 'Anika Rahman')
        ->assertJsonPath('data.*.full_name', ['Bilal Hossain', 'Chandni Akter'])
        ->assertJsonPath('data.0.reports_count', 1)
        ->assertJsonMissingPath('data.0.national_id');

    // A branch sees its own people at the top (their manager sits above the branch).
    $branchHr = createMember($this->w->b1, MembershipType::Owner);
    $this->asToken(orgToken($branchHr, $this->w->b1))->getJson("/api/organizations/{$this->w->b1->id}/hrm/org-chart")->assertOk()
        ->assertJsonPath('data.*.full_name', ['Esha Branch']);
});

it('keeps the org chart to people who may see it', function () {
    $anika = hireNamed($this, 'Anika Rahman');
    $clerk = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['audit.view'], 'Clerk'));
    $this->asToken(orgToken($clerk, $this->w->c1))->getJson("{$this->hr}/org-chart")->assertForbidden();

    // Another company cannot open this one's people.
    $other = createMember($this->w->c4, MembershipType::Owner);
    $this->asToken(orgToken($other, $this->w->c4))->getJson("/api/organizations/{$this->w->c4->id}/hrm/org-chart?manager_id={$anika}")->assertNotFound();

    toggles()->disable($this->w->g1, 'hrm', 'Stop HR');
    $this->asToken($this->w->token)->getJson("{$this->hr}/org-chart")->assertForbidden();
});

// ── Document expiry ──

it('tells HR once per stage, and once more when a document has expired', function () {
    // Keep the messages queued: a sent message's values are wiped after delivery.
    Queue::fake();
    $anika = hireNamed($this, 'Anika Rahman');
    documentFor($this, $anika, '2026-11-10');           // 26 days: the 30-day stage
    documentFor($this, $anika, '2026-10-14', 'Licence'); // yesterday: expired
    documentFor($this, $anika, '2027-03-01', 'Visa');    // far away: nothing

    Artisan::call('hrm:document-expiry');

    $deliveries = NotificationDelivery::query()->where('notification_key', 'hrm.document_expiring')->get();
    expect($deliveries)->toHaveCount(1)
        ->and($deliveries[0]->user_id)->toBe($this->w->owner->id)
        ->and($deliveries[0]->data['count'])->toBe('2')
        ->and($deliveries[0]->data['documents'])->toContain('Anika Rahman — Work permit — expires')
        ->and($deliveries[0]->data['documents'])->toContain('Licence — expired')
        ->and($deliveries[0]->data['documents'])->not->toContain('Visa')
        ->and(DocumentAlert::query()->withoutGlobalScopes()->pluck('stage')->sort()->values()->all())->toBe([-1, 30]);

    // The same day again: nothing new.
    Artisan::call('hrm:document-expiry');
    expect(NotificationDelivery::query()->where('notification_key', 'hrm.document_expiring')->count())->toBe(1);

    // Three weeks later the permit reaches the 7-day stage; the expired licence is not repeated.
    $this->travelTo(CarbonImmutable::parse('2026-11-05 06:00', 'UTC'));
    Artisan::call('hrm:document-expiry');
    $latest = NotificationDelivery::query()->where('notification_key', 'hrm.document_expiring')->latest('id')->first();
    expect(NotificationDelivery::query()->where('notification_key', 'hrm.document_expiring')->count())->toBe(2)
        ->and($latest->data['count'])->toBe('1')
        ->and($latest->data['documents'])->not->toContain('Licence');
});

it('sends nothing where HRM is off or reminders are turned off', function () {
    documentFor($this, hireNamed($this, 'Anika Rahman'), '2026-10-20');

    orgRule($this->w->c1, 'hrm.document_expiry_alert_days', []);
    Artisan::call('hrm:document-expiry');
    expect(NotificationDelivery::query()->where('notification_key', 'hrm.document_expiring')->count())->toBe(0);

    ruleService()->reset(app(RuleTargets::class)->organization($this->w->c1->fresh()), 'hrm.document_expiry_alert_days', 'Back to the default');
    toggles()->disable($this->w->g1, 'hrm', 'Stop HR');
    Artisan::call('hrm:document-expiry');
    expect(NotificationDelivery::query()->where('notification_key', 'hrm.document_expiring')->count())->toBe(0);
});

it('tells each company only about its own people', function () {
    documentFor($this, hireNamed($this, 'Anika Rahman'), '2026-10-20');
    $otherOwner = createMember($this->w->c4, MembershipType::Owner);

    Artisan::call('hrm:document-expiry');

    expect(NotificationDelivery::query()->where('notification_key', 'hrm.document_expiring')->pluck('user_id')->all())->toBe([$this->w->owner->id])
        ->and(NotificationDelivery::query()->where('user_id', $otherOwner->id)->exists())->toBeFalse();
});

it('shows documents to renew on the dashboard, in the bell and on the employee', function () {
    $anika = hireNamed($this, 'Anika Rahman');
    documentFor($this, $anika, '2026-10-14', 'Licence');
    documentFor($this, $anika, '2026-10-20');

    $this->asToken($this->w->token)->getJson('/api/modules/hrm/dashboard/expiring')->assertOk()
        ->assertJsonPath('data.items.0.label', 'Anika Rahman')
        ->assertJsonPath('data.items.0.meta', 'Licence · expired')
        ->assertJsonPath('data.items.0.tone', 'bad')
        ->assertJsonPath('data.items.1.meta', 'Work permit · expires in 5 days');

    $this->asToken($this->w->token)->getJson('/api/attention')->assertOk()
        ->assertJsonFragment(['key' => 'hrm.documents_expiring', 'label' => 'Employee documents to renew', 'count' => 2, 'path' => '/m/hrm', 'tone' => 'bad']);

    // Reading HRM is not managing it: no bell item.
    $viewer = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view'], 'HR viewer'));
    $this->asToken(orgToken($viewer, $this->w->c1))->getJson('/api/attention')->assertOk()->assertJsonMissing(['key' => 'hrm.documents_expiring']);

    $documents = $this->asToken($this->w->token)->getJson("{$this->hr}/employees/{$anika}/documents")->assertOk()->json('data');
    // Same upload time (time stands still here), so compare without order.
    expect(collect($documents)->pluck('expiry.state', 'title')->all())->toEqual(['Work permit' => 'expiring', 'Licence' => 'expired']);
});

it('lets HRM add its own notification to the catalog', function () {
    $catalog = app(NotificationCatalog::class);

    expect($catalog->has('hrm.document_expiring'))->toBeTrue()
        ->and($catalog->editableKeys())->toContain('hrm.document_expiring')
        ->and($catalog->textKey('hrm.document_expiring', 'templates'))->toBe('hrm::notifications.templates.hrm_document_expiring')
        ->and($catalog->textKey('support.requested', 'templates'))->toBe('notifications.templates.support_requested')
        ->and(__('hrm::notifications.templates.hrm_document_expiring.subject', [], 'bn'))->toContain('নবায়ন');
});
