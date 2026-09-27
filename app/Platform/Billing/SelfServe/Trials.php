<?php

namespace App\Platform\Billing\SelfServe;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Billing\Models\TrialGrant;
use App\Platform\Billing\SelfServe\Events\TrialEnded;
use App\Platform\Billing\SelfServe\Events\TrialEnding;
use App\Platform\Billing\SelfServe\Events\TrialStarted;
use App\Platform\Packaging\Actions\ChangePlan;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Payments\Exceptions\PaymentException;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Free trials of a paid personal plan (rules b2c.trial_days, b2c.trial_plan):
 * from a free plan only, once per person and partner. "Person" means the
 * account and each verified email or phone, so a second sign-up with the
 * same phone gets no second trial. When a trial ends without a purchase,
 * the account goes back to its free plan; all its data stays.
 */
class Trials
{
    public function __construct(
        private SelfServeAccount $account,
        private ChangePlan $changePlan,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{plan_key: string, ends_at: CarbonImmutable}|null  What a trial would give now; null = none offered.
     */
    public function offer(Organization $root, Subscription $subscription, User $user): ?array
    {
        $days = (int) $this->account->rule('b2c.trial_days', $root);
        $plan = (string) $this->account->rule('b2c.trial_plan', $root);

        if ($days < 1 || ! $this->account->isOffered($plan) || ($this->account->price($plan, $subscription) ?? 0) < 1) {
            return null;
        }

        if ($subscription->onTrial() || $this->account->isPaid($root, $subscription) || $this->used($root, $user)) {
            return null;
        }

        return ['plan_key' => $plan, 'ends_at' => CarbonImmutable::now()->addDays($days)];
    }

    public function start(Organization $root, User $user): Subscription
    {
        $subscription = $this->account->subscription($root);
        $this->account->assertVerified($user);

        if ((int) $this->account->rule('b2c.trial_days', $root) < 1) {
            throw PaymentException::trialsOff();
        }
        if ($subscription->onTrial() || $this->account->isPaid($root, $subscription)) {
            throw PaymentException::trialNotFromFree();
        }
        if ($this->used($root, $user)) {
            throw PaymentException::trialUsed();
        }

        $offer = $this->offer($root, $subscription, $user) ?? throw PaymentException::planNotOffered();

        return DB::transaction(function () use ($root, $user, $offer) {
            try {
                foreach ($this->subjects($user) as $subject) {
                    (new TrialGrant)->forceFill([
                        'partner_id' => $root->partner_id,
                        'subject' => $subject,
                        'user_id' => $user->getKey(),
                        'organization_id' => $root->getKey(),
                        'plan_key' => $offer['plan_key'],
                        'created_at' => CarbonImmutable::now(),
                    ])->save();
                }
            } catch (UniqueConstraintViolationException) {
                throw PaymentException::trialUsed();
            }

            $this->changePlan->handle($root, $offer['plan_key'], 'Free trial started.', $user);

            $subscription = Subscription::query()->where('organization_id', $root->getKey())->lockForUpdate()->firstOrFail();
            $subscription->forceFill([
                'self_serve' => true,
                'trial_ends_at' => $offer['ends_at'],
                'trial_plan_key' => $offer['plan_key'],
                'trial_reminded' => false,
            ])->save();

            $this->audit->record(
                action: 'billing.trial_started',
                target: $root,
                new: ['plan' => $offer['plan_key'], 'ends_at' => $offer['ends_at']->toIso8601String()],
                actor: $user,
                organizationId: $root->getKey(),
                partnerId: $root->partner_id,
            );

            TrialStarted::dispatch($root);

            return $subscription;
        });
    }

    /**
     * Reminds accounts whose trial ends soon, and moves ended trials back to
     * the free plan. Safe to repeat.
     *
     * @return array{reminded: int, ended: int}
     */
    public function sweep(CarbonImmutable $now): array
    {
        $counts = ['reminded' => 0, 'ended' => 0];

        $subscriptions = Subscription::query()->where('self_serve', true)->whereNotNull('trial_ends_at')->with('organization.partner')->lazyById();

        foreach ($subscriptions as $subscription) {
            $root = $subscription->organization;

            if (! $subscription->trial_ends_at->greaterThan($now)) {
                $this->end($root, $subscription);
                $counts['ended']++;

                continue;
            }

            $remindFrom = $subscription->trial_ends_at->subDays((int) $this->account->rule('b2c.trial_reminder_days', $root));
            if (! $subscription->trial_reminded && ! $now->lessThan($remindFrom)) {
                $subscription->forceFill(['trial_reminded' => true])->save();
                TrialEnding::dispatch($root, (string) $subscription->trial_plan_key, $subscription->trial_ends_at);
                $counts['reminded']++;
            }
        }

        return $counts;
    }

    /** Ends a trial now (it ran out, or the person moved to the free plan). */
    public function end(Organization $root, Subscription $subscription, ?User $actor = null): void
    {
        DB::transaction(function () use ($root, $subscription, $actor) {
            $subscription = Subscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();
            if (! $subscription->onTrial()) {
                return;
            }

            $free = $this->account->freePlan($root);
            if ($this->account->planKey($root) !== $free) {
                $this->changePlan->handle($root, $free, 'Free trial ended.', $actor);
            }

            $trialPlan = (string) $subscription->trial_plan_key;
            $subscription->refresh()->forceFill(['trial_ends_at' => null, 'trial_plan_key' => null, 'trial_reminded' => false])->save();

            // Ran out by itself: tell the person (when they chose it, they know).
            if ($actor === null) {
                TrialEnded::dispatch($root, $trialPlan);
            }
        });
    }

    private function used(Organization $root, User $user): bool
    {
        return TrialGrant::query()->where('partner_id', $root->partner_id)->whereIn('subject', $this->subjects($user))->exists();
    }

    /**
     * @return list<string>
     */
    private function subjects(User $user): array
    {
        $subjects = ['user:'.$user->getKey()];

        if ($user->email_verified_at !== null && $user->email !== null) {
            $subjects[] = hash('sha256', 'email:'.mb_strtolower(trim($user->email)));
        }
        if ($user->phone_verified_at !== null && $user->phone !== null) {
            $subjects[] = hash('sha256', 'phone:'.$user->phone);
        }

        return $subjects;
    }
}
