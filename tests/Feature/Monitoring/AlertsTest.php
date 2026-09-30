<?php

use App\Platform\Monitoring\AlertService;
use App\Platform\Monitoring\Jobs\DeliverSecurityAlert;
use App\Platform\Monitoring\Models\SecurityAlert;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Security\SecurityLog;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\SentMessage;

/*
 * Phase 9-2: security events past a threshold raise one alert, told once to
 * the platform operators (mail, Slack, SMS by severity) and, where it
 * concerns them, the person, the organization or the partner.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    config([
        'monitoring.platform.mail.to' => ['ops@platform.test'],
        'monitoring.platform.slack.webhook' => 'https://hooks.slack.test/services/T/B/X',
        // The sign-in limit would stop a test's burst before the threshold.
        'tenancy.throttle.login' => 1000,
        'security.api.per_ip' => 100000,
    ]);
    Http::fake(['hooks.slack.test/*' => Http::response('ok')]);
});

/** @return list<SentMessage> */
function opsMails(): array
{
    return array_values(array_filter(
        iterator_to_array(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()),
        fn ($message) => in_array('ops@platform.test', array_map(fn ($address) => $address->getAddress(), $message->getOriginalMessage()->getTo()), true),
    ));
}

function failSignIn(object $test, string $email): void
{
    $test->withHeader('Origin', config('app.url'))->postJson('/session/login', ['email' => $email, 'password' => 'wrong-password'])->assertUnprocessable();
}

it('raises one alert for a simulated brute force on an account, and tells the operators and the person once', function () {
    $user = createMember($this->w->c1);

    foreach (range(1, 9) as $attempt) {
        failSignIn($this, $user->email);
    }
    expect(SecurityAlert::count())->toBe(0);

    failSignIn($this, $user->email);

    $alert = SecurityAlert::query()->where('kind', 'brute_force_account')->sole();
    expect($alert->count)->toBe(10)
        ->and($alert->severity)->toBe('high')
        ->and($alert->user_id)->toBe($user->id)
        ->and($alert->notified_at)->not->toBeNull()
        ->and(opsMails())->toHaveCount(1)
        ->and(opsMails()[0]->getOriginalMessage()->getSubject())->toBe('[High] Many failed sign-ins to one account (10)')
        // The text names ids and the address, never the email or the password tried.
        ->and(opsMails()[0]->toString())->not->toContain($user->email)->not->toContain('wrong-password');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request['text'], 'Many failed sign-ins to one account'));
    expect(NotificationDelivery::query()->where('user_id', $user->id)->where('notification_key', 'security.sign_in_attempts')->exists())->toBeTrue();

    // More attempts count up the same alert; nobody is told again.
    failSignIn($this, $user->email);
    expect($alert->fresh()->count)->toBe(11)
        ->and(SecurityAlert::count())->toBe(1)
        ->and(opsMails())->toHaveCount(1);
    Http::assertSentCount(1);
});

it('raises an alert for many failed sign-ins from one address, for the operators only', function () {
    foreach (range(1, 30) as $attempt) {
        failSignIn($this, 'nobody'.$attempt.'@example.test');
    }

    $alert = SecurityAlert::query()->where('kind', 'brute_force_address')->sole();
    expect($alert->details['ip'])->toBe('127.0.0.1')
        ->and($alert->user_id)->toBeNull()
        ->and(NotificationDelivery::query()->where('notification_key', 'security.sign_in_attempts')->exists())->toBeFalse();
});

it('raises an alert on a cross-tenant attempt', function () {
    $attacker = createMember($this->w->c4);

    $this->asToken(orgToken($attacker, $this->w->c4))->getJson("/api/organizations/{$this->w->c1->id}")->assertNotFound();

    $alert = SecurityAlert::query()->where('kind', 'cross_tenant_attempt')->sole();
    expect($alert->severity)->toBe('high')
        ->and($alert->details['requested_organization_id'])->toBe($this->w->c1->id)
        ->and($alert->user_id)->toBe($attacker->id);
});

it('tells the organization about a data export, and only the operators\' channels its severity reaches', function () {
    $owner = createMember($this->w->c1);
    $otherOwner = createMember($this->w->c1);

    $this->asToken(orgToken($owner, $this->w->c1))->postJson("/api/organizations/{$this->w->c1->id}/exports")->assertSuccessful();

    $alert = SecurityAlert::query()->where('kind', 'data_export')->sole();
    expect($alert->organization_id)->toBe($this->w->c1->id)
        ->and($alert->severity)->toBe('low')
        ->and(NotificationDelivery::query()->where('notification_key', 'security.alert')->where('organization_id', $this->w->c1->id)->pluck('user_id')->all())
        // The other owner is told; the one who asked knows already.
        ->toContain($otherOwner->id)->not->toContain($owner->id)
        ->and(opsMails())->toHaveCount(1);
    // Low severity: email only; Slack takes medium and up.
    Http::assertNothingSent();
});

it('alerts on sensitive setting changes only', function () {
    $log = app(SecurityLog::class);

    $log->record('audit.rule.approved', ['rule' => 'attendance.late_grace_minutes', 'organization_id' => $this->w->c1->id]);
    expect(SecurityAlert::count())->toBe(0);

    $log->record('audit.rule.approved', ['rule' => 'identity.mfa_required', 'organization_id' => $this->w->c1->id]);
    expect(SecurityAlert::query()->where('kind', 'sensitive_rule_changed')->sole()->organization_id)->toBe($this->w->c1->id);
});

it('opens a new alert after the last one was acknowledged', function () {
    $log = app(SecurityLog::class);
    $log->record('tenant.cross_access_attempt', ['user_id' => (string) Str::ulid()]);
    $first = SecurityAlert::sole();

    app(AlertService::class)->acknowledge($first, 'ops', 'Checked: a test script.');
    $this->travel(2)->hours();
    $log->record('tenant.cross_access_attempt', ['user_id' => $first->user_id]);

    expect(SecurityAlert::count())->toBe(2)
        ->and($first->fresh()->acknowledged_by)->toBe('ops');
    $this->artisan('security:alerts')->expectsOutputToContain('Attempt to reach another organization')->assertSuccessful();
});

it('retries a channel that was down without sending the others twice', function () {
    // Replaces the fake from beforeEach (the first matching fake would win otherwise).
    Http::swap(new Factory(app('events')));
    Http::fake(['hooks.slack.test/*' => Http::sequence()->push('down', 500)->push('ok', 200)]);
    config(['queue.default' => 'database']);

    app(SecurityLog::class)->record('tenant.cross_access_attempt', ['user_id' => (string) Str::ulid()]);
    $alert = SecurityAlert::sole();
    $job = new DeliverSecurityAlert($alert->id);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(Exception::class);
    expect(opsMails())->toHaveCount(1)->and($alert->fresh()->notified_at)->toBeNull();

    app()->call([$job, 'handle']);
    expect(opsMails())->toHaveCount(1)->and($alert->fresh()->notified_at)->not->toBeNull();
    Http::assertSentCount(2);
});
