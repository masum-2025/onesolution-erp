<?php

namespace App\Platform\Billing\SelfServe;

use App\Platform\Billing\Models\Invoice;
use App\Platform\Billing\SelfServe\Events\PaymentOverdue;
use App\Platform\Packaging\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Unpaid self-serve invoices, day by day after their due date:
 *
 *  1. reminders on the days of rule billing.overdue_reminder_days;
 *  2. after rule billing.overdue_grace_days the account becomes read-only
 *     (it can still view, export, pay, or move to the free plan);
 *  3. paying or moving to the free plan restores it at once.
 *
 * No data is ever deleted. Safe to repeat: counters live on the subscription.
 */
class Dunning
{
    public function __construct(private SelfServeAccount $account, private AccountStanding $standing) {}

    /**
     * @return array{reminded: int, restricted: int, restored: int}
     */
    public function run(CarbonImmutable $now): array
    {
        $counts = ['reminded' => 0, 'restricted' => 0, 'restored' => 0];

        // Only accounts that owe something now, or were marked as owing (many small accounts: no full scan).
        $owing = Invoice::query()
            ->select('organization_id')
            ->where('billed_to', Invoice::TO_ORGANIZATION)
            ->where('type', Invoice::INVOICE)
            ->where('status', Invoice::ISSUED)
            ->where('due_at', '<', $now);

        $subscriptions = Subscription::query()
            ->where('self_serve', true)
            ->where(fn ($query) => $query->whereNotNull('past_due_since')->orWhereNotNull('restricted_at')->orWhereIn('organization_id', $owing))
            ->with('organization.partner')
            ->lazyById();

        foreach ($subscriptions as $subscription) {
            DB::transaction(function () use ($subscription, $now, &$counts) {
                $subscription = Subscription::query()->whereKey($subscription->getKey())->with('organization.partner')->lockForUpdate()->firstOrFail();
                $root = $subscription->organization;
                $overdue = $this->account->overdueInvoice($root, $now);

                if ($overdue === null) {
                    if ($subscription->restricted_at !== null) {
                        $counts['restored']++;
                    }
                    $this->standing->refresh($root, $subscription);

                    return;
                }

                $daysLate = (int) floor($overdue->due_at->diffInHours($now) / 24);
                $grace = (int) $this->account->rule('billing.overdue_grace_days', $root);
                $reminderDays = array_values(array_filter(
                    array_map('intval', (array) $this->account->rule('billing.overdue_reminder_days', $root)),
                    fn (int $day) => $day <= $daysLate,
                ));

                $subscription->past_due_since ??= $overdue->due_at;

                // One reminder per run at most, however many reminder days were missed.
                if (count($reminderDays) > $subscription->reminders_sent && $daysLate < $grace) {
                    $subscription->reminders_sent = count($reminderDays);
                    $subscription->save();
                    PaymentOverdue::dispatch($root, $overdue, $overdue->due_at->addDays($grace));
                    $counts['reminded']++;
                }

                $subscription->save();

                if ($daysLate >= $grace && $subscription->restricted_at === null) {
                    $this->standing->restrict($root, $subscription);
                    $counts['restricted']++;
                }
            });
        }

        return $counts;
    }
}
