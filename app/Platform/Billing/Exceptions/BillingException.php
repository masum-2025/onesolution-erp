<?php

namespace App\Platform\Billing\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

class BillingException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'billing.errors.'.$this->errorCode;
    }

    public static function invoiceNotFound(): self
    {
        return new self('invoice_not_found', 404);
    }

    public static function planNotFound(): self
    {
        return new self('plan_not_found', 404);
    }

    public static function notPayable(): self
    {
        return new self('not_payable', 422);
    }

    public static function notCreditable(): self
    {
        return new self('not_creditable', 422);
    }

    public static function creditTooLarge(int $remainingMinor, string $currency): self
    {
        return new self('credit_too_large', 422, ['remaining' => (string) $remainingMinor, 'currency' => $currency]);
    }

    public static function nothingToPay(string $currency): self
    {
        return new self('nothing_to_pay', 422, ['currency' => $currency]);
    }

    public static function topLevelOnly(string $organization): self
    {
        return new self('top_level_only', 403, ['organization' => $organization]);
    }

    public static function roleNotAllowed(): self
    {
        return new self('role_not_allowed', 403);
    }
}
