<?php

namespace App\Platform\Support\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Form request that rejects any top-level field not declared in rules().
 * Authorization happens in policies, so authorize() allows by default.
 */
abstract class StrictFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $allowed = array_unique(array_map(
                    fn (string $key) => Str::before($key, '.'),
                    array_keys($this->rules()),
                ));

                foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                    $validator->errors()->add($field, __('tenancy.validation.unknown_field', ['field' => $field]));
                }
            },
        ];
    }
}
