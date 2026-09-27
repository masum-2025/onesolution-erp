<?php

namespace App\Platform\Billing\Http\Requests;

/**
 * A personal plan and period to buy now.
 */
class CheckoutRequest extends SelfServePlanRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...parent::rules(), ...self::opRule()];
    }
}
