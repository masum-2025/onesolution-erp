<?php

namespace App\Platform\Billing\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (POST) or change (PATCH) a partner plan. On PATCH every field is
 * optional; what is sent replaces what was there.
 */
class PartnerPlanRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';
        $locales = LanguageRegistry::codes();

        return [
            'base_plan_key' => [$required, 'string', Rule::in(app(PlanCatalog::class)->keys())],
            'name' => [$required, 'array:'.implode(',', $locales)],
            'name.en' => [$creating ? 'required' : 'required_with:name', 'string', 'min:2', 'max:60'],
            'name.*' => ['nullable', 'string', 'max:60'],
            'description' => ['sometimes', 'nullable', 'array:'.implode(',', $locales)],
            'description.*' => ['nullable', 'string', 'max:300'],
            // null = every module of the base plan.
            'modules' => ['sometimes', 'nullable', 'array', 'max:100'],
            'modules.*' => ['string', 'distinct', 'regex:/^[a-z][a-z0-9_]*$/'],
            'prices' => [$required, 'array', 'min:1', 'max:20'],
            'prices.*' => ['array:currency,period,amount_minor'],
            'prices.*.currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'prices.*.period' => ['required', 'string', Rule::in(PlanCatalog::PERIODS)],
            'prices.*.amount_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator) {
            // One price per currency and period.
            $seen = [];
            foreach ((array) $this->input('prices', []) as $index => $price) {
                $key = ($price['currency'] ?? '').'/'.($price['period'] ?? '');
                if (isset($seen[$key])) {
                    $validator->errors()->add("prices.{$index}.currency", __('billing.errors.duplicate_price'));
                }
                $seen[$key] = true;
            }
        }];
    }
}
