<?php

namespace App\Platform\Packaging\Http\Requests;

use App\Platform\Packaging\PlanCatalog;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

class PreviewPlanRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan' => ['required_without:partner_plan_id', 'nullable', 'string', Rule::in(app(PlanCatalog::class)->keys())],
            'partner_plan_id' => ['nullable', 'string', 'ulid'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'period' => ['nullable', 'string', Rule::in(PlanCatalog::PERIODS)],
        ];
    }
}
