<?php

namespace App\Platform\Addons\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/** An add-on step refused: a translated message that says what to do, and a stable code. */
class AddonException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'addons.errors.'.$this->errorCode;
    }

    public static function storeClosed(): self
    {
        return new self('addon_store_closed', 403);
    }

    public static function notAllowed(): self
    {
        return new self('addon_not_allowed', 403);
    }

    public static function selfPurchaseOff(string $provider): self
    {
        return new self('addon_ask_provider', 403, ['provider' => $provider]);
    }

    public static function unknown(): self
    {
        return new self('addon_unknown', 404);
    }

    public static function notForSale(string $module): self
    {
        return new self('addon_not_for_sale', 422, ['module' => $module]);
    }

    public static function inPlan(string $module): self
    {
        return new self('addon_in_plan', 422, ['module' => $module]);
    }

    public static function alreadyRunning(string $module): self
    {
        return new self('addon_already_running', 409, ['module' => $module]);
    }

    public static function noPrice(string $module, string $currency): self
    {
        return new self('addon_no_price', 422, ['module' => $module, 'currency' => $currency]);
    }

    public static function trialUsed(string $module): self
    {
        return new self('addon_trial_used', 422, ['module' => $module]);
    }

    public static function noTrial(): self
    {
        return new self('addon_no_trial', 422);
    }

    /**
     * @param  list<string>  $keys
     */
    public static function needsFirst(string $module, string $missing, array $keys): self
    {
        return new self('addon_needs_first', 422, ['module' => $module, 'missing' => $missing], ['missing' => $keys]);
    }

    public static function notRunning(string $module): self
    {
        return new self('addon_not_running', 422, ['module' => $module]);
    }

    public static function opReused(): self
    {
        return new self('addon_op_reused', 409);
    }

    public static function moduleOff(string $module): self
    {
        return new self('addon_module_off', 422, ['module' => $module]);
    }
}
