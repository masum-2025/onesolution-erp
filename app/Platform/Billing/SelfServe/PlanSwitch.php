<?php

namespace App\Platform\Billing\SelfServe;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Billing\Services\CreditNotes;
use App\Platform\Packaging\Actions\ChangePlan;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Payments\Exceptions\PaymentException;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Moving a self-serve account to its free plan, which is never a dead end:
 *
 * - unpaid invoices are cancelled with credit notes and the move is
 *   immediate (a read-only account works again at once);
 * - during a trial, the trial ends now;
 * - during a paid period, the plan stays until the period ends (the person
 *   paid for it) and can be kept after all until then;
 * - modules the free plan lacks switch off; their data stays.
 */
class PlanSwitch
{
    public function __construct(
        private SelfServeAccount $account,
        private AccountStanding $standing,
        private Trials $trials,
        private CreditNotes $creditNotes,
        private ChangePlan $changePlan,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{when: string, on: string|null}  when: now | period_end
     */
    public function toFree(Organization $root, User $user): array
    {
        $subscription = $this->account->subscription($root);
        // A company has no free plan to move to (it can pay, or its owner can close it).
        $free = $this->account->freePlan($root) ?? throw PaymentException::noFreePlan();
        $open = $this->account->openInvoices($root);

        if ($subscription->onTrial() && $open->isEmpty()) {
            $this->trials->end($root, $subscription, $user);

            return ['when' => 'now', 'on' => null];
        }

        if ($this->account->planKey($root) === $free && $open->isEmpty()) {
            throw PaymentException::alreadyFree();
        }

        $paidUntil = $subscription->billed_through;
        if ($open->isEmpty() && $paidUntil !== null && ! $paidUntil->lessThan($this->account->today())) {
            if (! $subscription->cancel_at_period_end) {
                $subscription->forceFill(['cancel_at_period_end' => true])->save();
                $this->record('billing.cancel_scheduled', $root, $user, ['until' => $paidUntil->toDateString()]);
            }

            return ['when' => 'period_end', 'on' => $paidUntil->toDateString()];
        }

        DB::transaction(function () use ($root, $user, $free, $open) {
            foreach ($open as $invoice) {
                $this->creditNotes->issue($invoice, $this->creditNotes->remaining($invoice), 'Cancelled: the client moved to the free plan.', $user);
            }

            if ($this->account->planKey($root) !== $free) {
                $this->changePlan->handle($root, $free, 'Moved to the free plan (self-serve).', $user);
            }

            $subscription = Subscription::query()->where('organization_id', $root->getKey())->lockForUpdate()->firstOrFail();
            $subscription->forceFill([
                // Nothing paid runs on: the next purchase starts a new period today.
                'billed_through' => null,
                'cancel_at_period_end' => false,
                'trial_ends_at' => null,
                'trial_plan_key' => null,
                'trial_reminded' => false,
            ])->save();

            $this->standing->refresh($root, $subscription, $user);
        });

        return ['when' => 'now', 'on' => null];
    }

    /** Undo a move to the free plan that has not happened yet. */
    public function keep(Organization $root, User $user): Subscription
    {
        $subscription = $this->account->subscription($root);
        if (! $subscription->cancel_at_period_end) {
            throw PaymentException::nothingToKeep();
        }

        $subscription->forceFill(['cancel_at_period_end' => false])->save();
        $this->record('billing.cancel_undone', $root, $user, ['plan' => $this->account->planKey($root)]);

        return $subscription;
    }

    /**
     * @param  array<string, mixed>  $new
     */
    private function record(string $action, Organization $root, User $user, array $new): void
    {
        $this->audit->record(
            action: $action,
            target: $root,
            new: $new,
            actor: $user,
            organizationId: $root->getKey(),
            partnerId: $root->partner_id,
        );
    }
}
