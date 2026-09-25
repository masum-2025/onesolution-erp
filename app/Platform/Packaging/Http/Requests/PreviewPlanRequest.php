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
            'plan' => ['required', 'string', Rule::in(app(PlanCatalog::class)->keys())],
        ];
    }
}
