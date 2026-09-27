<?php

namespace App\Platform\Billing\Http\Requests;

use App\Platform\Packaging\PlanCatalog;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

/**
 * A personal plan and period to price.
 */
class SelfServePlanRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan_key' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]{1,49}$/'],
            'period' => ['required', 'string', Rule::in(PlanCatalog::PERIODS)],
        ];
    }

    /**
     * One click = one op_id, so a retry never starts a second payment.
     *
     * @return array<string, mixed>
     */
    public static function opRule(): array
    {
        return ['op_id' => ['required', 'string', 'min:16', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/']];
    }
}
