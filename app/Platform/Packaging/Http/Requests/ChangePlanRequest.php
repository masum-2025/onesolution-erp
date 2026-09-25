<?php

namespace App\Platform\Packaging\Http\Requests;

use App\Platform\Packaging\PlanCatalog;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

class ChangePlanRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', Rule::in(app(PlanCatalog::class)->keys())],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
