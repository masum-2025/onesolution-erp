<?php

namespace App\Platform\Billing\SelfServe;

use App\Platform\Billing\Models\Invoice;
use App\Platform\Billing\Services\Commissions;
use App\Platform\Billing\Services\InvoiceDraft;
use App\Platform\Billing\Services\InvoiceIssuer;
use App\Platform\Billing\Services\LineTexts;
use App\Platform\Packaging\Actions\ChangePlan;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Self-serve periods renew on their own dates, in advance: the renewal
 * invoice for the next period is issued a few days before the paid one ends
 * (rule billing.renewal_notice_days), due on the day the new period starts.
 * There is no stored card: the person pays the invoice online. An account
 * that already owes an invoice gets no new one until it is settled.
 *
 * An account that chose the free plan moves to it when its paid period ends.
 * Safe to repeat: each renewal has its own billing key.
 */
class Renewals
{
    public function __construct(
        private SelfServeAccount $account,
        private InvoiceIssuer $issuer,
        private Commissions $commissions,
        private LineTexts $texts,
        private ChangePlan $changePlan,
    ) {}

    /**
     * @return array{issued: list<string>, moved_to_free: int}
     */
    public function run(CarbonImmutable $now): array
    {
        $result = ['issued' => [], 'moved_to_free' => 0];
        $today = $now->utc()->startOfDay();

        $subscriptions = Subscription::query()
            ->where('self_serve', true)
            ->where('status', Subscription::ACTIVE)
            ->whereNotNull('billed_through')
            // Only periods ending soon: the notice rule is at most 30 days (no full scan).
            ->where('billed_through', '<=', $today->addDays(30)->toDateString())
            ->whereNull('trial_ends_at')
            ->with('organization.partner')
            ->lazyById();

        foreach ($subscriptions as $subscription) {
            $root = $subscription->organization;
            if ($root->status !== OrganizationStatus::Active || ! $this->account->isPaid($root, $subscription)) {
                continue;
            }

            if ($subscription->cancel_at_period_end) {
                if ($subscription->billed_through->lessThan($today)) {
                    $this->moveToFree($root, $subscription);
                    $result['moved_to_free']++;
                }

                continue;
            }

            $noticeDays = (int) $this->account->rule('billing.renewal_notice_days', $root);
            if ($subscription->billed_through->subDays($noticeDays)->greaterThan($today)) {
                continue;
            }

            if ($this->account->openInvoices($root)->isNotEmpty()) {
                continue;
            }

            $number = $this->renew($root, $subscription, $today);
            if ($number !== null) {
                $result['issued'][] = $number;
            }
        }

        return $result;
    }

    private function renew(Organization $root, Subscription $subscription, CarbonImmutable $today): ?string
    {
        return DB::transaction(function () use ($root, $subscription, $today) {
            $subscription = Subscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();
            $start = $subscription->billed_through->addDay();
            $end = $this->account->periodEnd($start, $subscription->period);
            $planKey = $this->account->planKey($root);
            $price = $this->account->price($planKey, $subscription);

            if ($price === null || $price < 1) {
                return null;
            }

            try {
                $invoice = $this->issuer->issue(new InvoiceDraft(
                    type: Invoice::INVOICE,
                    billedTo: Invoice::TO_ORGANIZATION,
                    partner: $root->partner,
                    organization: $root,
                    brandPartner: $root->partner,
                    billingMode: $root->partner->billing_mode->value,
                    currency: $subscription->currency_code,
                    taxRateBp: (int) $this->account->rule('billing.tax_rate_bp', $root),
                    // Due on the first day of the new period.
                    paymentTermsDays: max(0, (int) $today->diffInDays($start)),
                    lines: [[
                        'organization_id' => $root->getKey(),
                        'plan_key' => $planKey,
                        'description' => $this->texts->make("subscription_{$subscription->period}", fn (string $locale) => [
                            'plan' => $this->texts->planName($planKey, null, $locale),
                            'from' => $this->texts->day($start, $locale),
                            'to' => $this->texts->day($end, $locale),
                        ]),
                        'quantity' => 1,
                        'unit_amount_minor' => $price,
                    ]],
                    billingKey: "renewal:{$subscription->getKey()}:{$start->toDateString()}",
                    periodStart: $start,
                    periodEnd: $end,
                ));
            } catch (UniqueConstraintViolationException) {
                return null;
            }

            $this->commissions->record($invoice);
            $subscription->forceFill(['billed_through' => $end->toDateString()])->save();

            return $invoice->number;
        });
    }

    private function moveToFree(Organization $root, Subscription $subscription): void
    {
        DB::transaction(function () use ($root, $subscription) {
            $free = $this->account->freePlan($root);
            if ($this->account->planKey($root) !== $free) {
                $this->changePlan->handle($root, $free, 'Moved to the free plan at the end of the paid period, as the client chose.', null);
            }

            $subscription->refresh()->forceFill(['cancel_at_period_end' => false])->save();
        });
    }
}
