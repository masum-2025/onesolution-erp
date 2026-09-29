<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Billing\SelfServe\Events\TrialEnded;
use App\Platform\Billing\SelfServe\Events\TrialEnding;
use App\Platform\Identity\Services\PersonalWorkspaces;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Packaging\Services\SubscriptionService;
use App\Platform\Payments\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/*
 * Free trials of a paid personal plan (Phase 5C-2): from the free plan,
 * once per person (account, verified email, verified phone), back to the
 * free plan with all data when it runs out.
 */

beforeEach(function () {
    $this->world = selfServeWorld();
    fakeSslCommerz();
});

function startTrial(object $world): TestResponse
{
    return test()->asToken(orgToken($world->user, $world->workspace))
        ->postJson("/api/organizations/{$world->workspace->id}/billing/trial");
}

function subscriptionOf(object $world): Subscription
{
    return app(SubscriptionService::class)->for($world->workspace->fresh());
}

it('starts a trial of the paid plan from the free plan', function () {
    startTrial($this->world)
        ->assertOk()
        ->assertJsonPath('data.status', 'trial')
        ->assertJsonPath('data.plan.key', 'personal_plus')
        ->assertJsonPath('data.trial_offer', null)
        ->assertJsonPath('message', 'Your free trial has started. Enjoy!');

    expect($this->world->workspace->fresh()->plan_key)->toBe('personal_plus')
        ->and(subscriptionOf($this->world)->trial_ends_at->toDateString())->toBe(CarbonImmutable::now()->addDays(14)->toDateString())
        ->and(AuditLog::query()->where('action', 'billing.trial_started')->exists())->toBeTrue();
});

it('gives a trial once per person', function () {
    startTrial($this->world)->assertOk();

    // Back on the free plan, the same person asks again.
    $this->travel(15)->days();
    $this->artisan('billing:self-serve')->assertSuccessful();
    expect($this->world->workspace->fresh()->plan_key)->toBe('personal_free');

    startTrial($this->world)->assertStatus(422)->assertJsonPath('code', 'trial_used');
});

it('gives no second trial to a new account with the same verified phone', function () {
    $this->world->user->forceFill(['phone' => '+8801712345678', 'phone_verified_at' => now()])->save();
    startTrial($this->world)->assertOk();

    // The first account gives the number up; a new account verifies it.
    $this->world->user->forceFill(['phone' => null, 'phone_verified_at' => null])->save();
    $again = User::factory()->create(['phone' => '+8801712345678', 'phone_verified_at' => now()]);
    $workspace = app(PersonalWorkspaces::class)->create($again, $this->world->partner, 'BD', 'en');

    startTrial((object) ['user' => $again, 'workspace' => $workspace])->assertStatus(422)->assertJsonPath('code', 'trial_used');
});

it('asks for a confirmed email or phone first', function () {
    $this->world->user->forceFill(['email_verified_at' => null])->save();

    startTrial($this->world)->assertStatus(422)->assertJsonPath('code', 'unverified');
});

it('offers no trial when the partner turned trials off', function () {
    partnerRule($this->world->partner, 'b2c.trial_days', 0);

    $this->asToken(orgToken($this->world->user, $this->world->workspace))
        ->getJson("/api/organizations/{$this->world->workspace->id}/billing/self-serve")
        ->assertJsonPath('data.trial_offer', null);
    startTrial($this->world)->assertStatus(422)->assertJsonPath('code', 'trials_off');
});

it('reminds before the end, then returns to the free plan with everything kept', function () {
    Event::fake([TrialEnding::class, TrialEnded::class]);
    startTrial($this->world)->assertOk();
    $members = $this->world->workspace->memberships()->count();

    // Day 11 of 14: the reminder (rule b2c.trial_reminder_days = 3) is due.
    $this->travel(11)->days();
    $this->artisan('billing:self-serve')->assertSuccessful();
    Event::assertDispatchedTimes(TrialEnding::class, 1);

    // Repeating the hourly run does not remind twice.
    $this->artisan('billing:self-serve')->assertSuccessful();
    Event::assertDispatchedTimes(TrialEnding::class, 1);

    $this->travel(4)->days();
    $this->artisan('billing:self-serve')->assertSuccessful();

    Event::assertDispatched(TrialEnded::class, fn (TrialEnded $event) => $event->planKey === 'personal_plus');
    expect($this->world->workspace->fresh()->plan_key)->toBe('personal_free')
        ->and(subscriptionOf($this->world)->trial_ends_at)->toBeNull()
        ->and($this->world->workspace->fresh()->status->value)->toBe('active')
        ->and($this->world->workspace->memberships()->count())->toBe($members);
});

it('ends the trial when the plan is bought, keeping the plan', function () {
    startTrial($this->world)->assertOk();

    $payment = Payment::query()->findOrFail(startCheckout($this, $this->world)->json('data.id'));
    $this->post('/payments/sslcommerz/notify', sslNotice($payment))->assertOk();

    $subscription = subscriptionOf($this->world);
    expect($subscription->trial_ends_at)->toBeNull()
        ->and($subscription->billed_through->toDateString())->toBe(CarbonImmutable::now('UTC')->addMonthNoOverflow()->subDay()->toDateString())
        ->and($this->world->workspace->fresh()->plan_key)->toBe('personal_plus');

    // The trial's end passing changes nothing now.
    $this->travel(20)->days();
    $this->artisan('billing:self-serve')->assertSuccessful();
    expect($this->world->workspace->fresh()->plan_key)->toBe('personal_plus');
});

it('can end a trial early by moving to the free plan', function () {
    startTrial($this->world)->assertOk();

    $this->asToken(orgToken($this->world->user, $this->world->workspace))
        ->postJson("/api/organizations/{$this->world->workspace->id}/billing/free", ['confirm' => true])
        ->assertOk()
        ->assertJsonPath('data.status', 'free')
        ->assertJsonPath('message', 'You are now on the free plan. All your data is kept.');

    expect($this->world->workspace->fresh()->plan_key)->toBe('personal_free');
});
