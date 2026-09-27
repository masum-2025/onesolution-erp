<?php

namespace App\Platform\Payments\Services;

use App\Models\User;
use App\Platform\Payments\CallbackUrls;
use App\Platform\Payments\Contracts\PaymentGateway;
use App\Platform\Payments\Exceptions\PaymentException;
use App\Platform\Payments\GatewayRegistry;
use App\Platform\Payments\Models\Payment;
use App\Platform\Payments\PaymentCollectables;
use App\Platform\Payments\PaymentCustomer;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A customer pays a client for one record (Phase 6), into the client's own
 * merchant account. The public service modules call from their own screens
 * (a parent paying a fee from the portal, a patient paying a bill):
 *
 * - the amount comes from the owning module (CollectableProvider::due), never
 *   from the request;
 * - the money goes to the company's active merchant account; the platform's
 *   own account is never used for a client's customers;
 * - the same op_id (one click) always gives back the same payment and page.
 */
class CollectPayment
{
    public function __construct(
        private PaymentCollectables $collectables,
        private MerchantAccounts $accounts,
        private GatewayRegistry $gateways,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function start(Organization $organization, User $payer, string $kind, string $id, string $opId): Payment
    {
        $existing = Payment::query()->where('organization_id', $organization->getKey())->where('op_id', $opId)->first();
        if ($existing !== null) {
            if ($existing->purpose !== Payment::COLLECTION || $existing->subject_type !== $kind || $existing->subject_id !== $id || $existing->user_id !== $payer->getKey()) {
                throw PaymentException::opReused();
            }

            return $existing;
        }

        if (! $this->collectables->usable($kind, $organization)) {
            throw PaymentException::kindNotAvailable();
        }

        $due = $this->collectables->provider($kind)->due($organization, $id, $payer);
        if ($due === null || $due->amountMinor <= 0) {
            throw PaymentException::nothingDue();
        }

        $company = $this->accounts->companyOf($organization) ?? throw PaymentException::noMerchantAccount();
        $account = $this->accounts->collectingAccount($company, $due->currency) ?? throw PaymentException::noMerchantAccount();
        $gateway = $this->gateways->forAccount($account);
        if ($gateway === null || ! $gateway->isAvailable() || ! $gateway->supportsCurrency($due->currency)) {
            throw PaymentException::noMerchantAccount();
        }

        $minutes = (int) $this->rules->get('online_payments.payment_expiry_minutes', $this->contexts->forOrganization($organization));

        $payment = new Payment;
        $payment->forceFill([
            'organization_id' => $organization->getKey(),
            'partner_id' => $organization->partner_id,
            'merchant_account_id' => $account->getKey(),
            'user_id' => $payer->getKey(),
            'purpose' => Payment::COLLECTION,
            'subject_type' => $kind,
            'subject_id' => $id,
            'gateway' => $account->gateway,
            'op_id' => $opId,
            'currency_code' => $due->currency,
            // Tax, if any, is part of what the module says is due.
            'subtotal_minor' => $due->amountMinor,
            'tax_rate_bp' => 0,
            'tax_minor' => 0,
            'amount_minor' => $due->amountMinor,
            'status' => Payment::PENDING,
            'expires_at' => CarbonImmutable::now()->addMinutes($minutes),
        ]);

        try {
            $payment->save();
        } catch (UniqueConstraintViolationException) {
            return Payment::query()->where('organization_id', $organization->getKey())->where('op_id', $opId)->first()
                ?? throw PaymentException::opReused();
        }

        try {
            $session = $gateway->start($payment, $this->customer($payer, $organization), $this->urls($gateway));
        } catch (PaymentException $exception) {
            $payment->forceFill(['status' => Payment::FAILED, 'failure_code' => 'gateway_error'])->save();

            throw $exception;
        }

        $payment->forceFill(['gateway_ref' => $session->reference, 'checkout_url' => $session->redirectUrl])->save();

        return $payment;
    }

    private function customer(User $payer, Organization $organization): PaymentCustomer
    {
        return new PaymentCustomer(
            name: $payer->name,
            email: $payer->email_verified_at !== null ? $payer->email : null,
            phone: $payer->phone_verified_at !== null ? $payer->phone : null,
            countryCode: $organization->country_code ?? (string) config('tenancy.defaults.country_code'),
        );
    }

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
