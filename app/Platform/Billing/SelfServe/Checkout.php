<?php

namespace App\Platform\Billing\SelfServe;

use App\Models\User;
use App\Platform\Billing\Models\Invoice;
use App\Platform\Payments\CallbackUrls;
use App\Platform\Payments\Contracts\PaymentGateway;
use App\Platform\Payments\Exceptions\PaymentException;
use App\Platform\Payments\GatewayRegistry;
use App\Platform\Payments\Models\Payment;
use App\Platform\Payments\PaymentCustomer;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Starts paying online: for a plan at checkout, or for an open invoice.
 * The amount is fixed here, by the server, before the gateway is called.
 * The same op_id (one click) always gives back the same payment and page,
 * so a double click or a retry after a lost answer never pays twice.
 */
class Checkout
{
    public function __construct(
        private SelfServeAccount $account,
        private Quotes $quotes,
        private GatewayRegistry $gateways,
    ) {}

    public function forPlan(Organization $root, User $user, string $planKey, string $period, string $opId): Payment
    {
        $this->account->assertVerified($user);

        $existing = $this->existing($root, $opId);
        if ($existing !== null) {
            if ($existing->purpose !== Payment::CHECKOUT || $existing->plan_key !== $planKey || $existing->period !== $period) {
                throw PaymentException::opReused();
            }

            return $existing;
        }

        $quote = $this->quotes->quote($root, $planKey, $period);

        return $this->start($root, $user, $opId, [
            'purpose' => Payment::CHECKOUT,
            'plan_key' => $quote->planKey,
            'period' => $quote->period,
            'currency_code' => $quote->currency,
            'subtotal_minor' => $quote->subtotalMinor,
            'tax_rate_bp' => $quote->taxRateBp,
            'tax_minor' => $quote->taxMinor,
            'amount_minor' => $quote->totalMinor(),
        ]);
    }

    public function forInvoice(Organization $root, User $user, string $invoiceId, string $opId): Payment
    {
        $this->account->subscription($root);
        $this->account->assertVerified($user);

        $existing = $this->existing($root, $opId);
        if ($existing !== null) {
            if ($existing->purpose !== Payment::INVOICE || $existing->invoice_id !== $invoiceId) {
                throw PaymentException::opReused();
            }

            return $existing;
        }

        $invoice = $this->account->openInvoices($root)->firstWhere(fn (Invoice $open) => $open->getKey() === $invoiceId)
            ?? throw PaymentException::invoiceNotPayable();

        return $this->start($root, $user, $opId, [
            'purpose' => Payment::INVOICE,
            'invoice_id' => $invoice->getKey(),
            'currency_code' => $invoice->currency_code,
            'subtotal_minor' => $invoice->subtotal_minor,
            'tax_rate_bp' => $invoice->tax_rate_bp,
            'tax_minor' => $invoice->tax_minor,
            'amount_minor' => $invoice->total_minor,
        ]);
    }

    /**
     * @param  array<string, mixed>  $terms
     */
    private function start(Organization $root, User $user, string $opId, array $terms): Payment
    {
        $gateway = $this->gateway($root, $terms['currency_code']);

        $payment = new Payment;
        $payment->forceFill([
            ...$terms,
            'organization_id' => $root->getKey(),
            'partner_id' => $root->partner_id,
            'user_id' => $user->getKey(),
            'gateway' => $gateway->key(),
            'op_id' => $opId,
            'status' => Payment::PENDING,
            'expires_at' => CarbonImmutable::now()->addMinutes((int) $this->account->rule('billing.checkout_expiry_minutes', $root)),
        ]);

        try {
            $payment->save();
        } catch (UniqueConstraintViolationException) {
            // The same click arrived twice at once: the first one carries on.
            return $this->existing($root, $opId) ?? throw PaymentException::opReused();
        }

        try {
            $session = $gateway->start($payment, $this->customer($user, $root), $this->urls($gateway));
        } catch (PaymentException $exception) {
            $payment->forceFill(['status' => Payment::FAILED, 'failure_code' => 'gateway_error'])->save();

            throw $exception;
        }

        $payment->forceFill(['gateway_ref' => $session->reference, 'checkout_url' => $session->redirectUrl])->save();

        return $payment;
    }

    private function existing(Organization $root, string $opId): ?Payment
    {
        return Payment::query()->where('organization_id', $root->getKey())->where('op_id', $opId)->first();
    }

    private function gateway(Organization $root, string $currency): PaymentGateway
    {
        return $this->gateways->available($this->account->context($root), $currency)[0] ?? throw PaymentException::noGateway();
    }

    private function customer(User $user, Organization $root): PaymentCustomer
    {
        return new PaymentCustomer(
            name: $user->name,
            email: $user->email_verified_at !== null ? $user->email : null,
            phone: $user->phone_verified_at !== null ? $user->phone : null,
            countryCode: $root->country_code ?? (string) config('tenancy.defaults.country_code'),
        );
    }

    /**
     * On the address the person is using (a partner's own domain keeps its
     * brand and session); the gateway's server posts its notice there too.
     */
    private function urls(PaymentGateway $gateway): CallbackUrls
    {
        $key = $gateway->key();

        return new CallbackUrls(
            success: url("/payments/{$key}/return/success"),
            fail: url("/payments/{$key}/return/fail"),
            cancel: url("/payments/{$key}/return/cancel"),
            notify: url("/payments/{$key}/notify"),
        );
    }
}
