<?php

namespace App\Platform\Payments\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Online payment and self-serve plan errors (Phase 5C-2). Messages tell the
 * person what to do next: lang/{locale}/payments.php "errors.*".
 */
class PaymentException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'payments.errors.'.$this->errorCode;
    }

    public static function notSelfServe(): self
    {
        return new self('not_self_serve', 422);
    }

    public static function billedByProvider(string $provider): self
    {
        return new self('billed_by_provider', 422, ['provider' => $provider]);
    }

    public static function notAllowed(): self
    {
        return new self('not_allowed', 403);
    }

    public static function unverified(): self
    {
        return new self('unverified', 422, [], ['verify' => true]);
    }

    public static function planNotOffered(): self
    {
        return new self('plan_not_offered', 422, [], ['field' => 'plan_key']);
    }

    public static function noPrice(): self
    {
        return new self('no_price', 422, [], ['field' => 'period']);
    }

    public static function alreadyOnPlan(string $until): self
    {
        return new self('already_on_plan', 422, ['until' => $until]);
    }

    public static function changeAtPeriodEnd(string $until): self
    {
        return new self('change_at_period_end', 422, ['until' => $until]);
    }

    public static function payOpenInvoice(string $number): self
    {
        return new self('pay_open_invoice', 422, ['number' => $number], ['invoice_number' => $number]);
    }

    public static function noGateway(): self
    {
        return new self('no_gateway', 422);
    }

    public static function gatewayUnavailable(): self
    {
        return new self('gateway_unavailable', 503);
    }

    public static function paymentNotFound(): self
    {
        return new self('payment_not_found', 404);
    }

    public static function invoiceNotPayable(): self
    {
        return new self('invoice_not_payable', 422);
    }

    public static function opReused(): self
    {
        return new self('op_reused', 409);
    }

    public static function trialsOff(): self
    {
        return new self('trials_off', 422);
    }

    public static function trialUsed(): self
    {
        return new self('trial_used', 422);
    }

    public static function trialNotFromFree(): self
    {
        return new self('trial_not_from_free', 422);
    }

    public static function alreadyFree(): self
    {
        return new self('already_free', 422);
    }

    public static function noFreePlan(): self
    {
        return new self('no_free_plan', 422);
    }

    public static function nothingToKeep(): self
    {
        return new self('nothing_to_keep', 422);
    }
}
