<?php

namespace App\Platform\Localization\Http\Requests;

use App\Platform\Localization\Enums\LanguageStatus;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

/** A database language: new (code required) or changed. */
class LanguageRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'code' => $creating ? ['required', 'string', 'max:20'] : ['prohibited'],
            'name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'english_name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'direction' => ['sometimes', 'nullable', Rule::in(['ltr', 'rtl'])],
            'fallback' => ['sometimes', 'nullable', 'string', 'max:20'],
            'status' => $creating ? ['prohibited'] : ['sometimes', Rule::enum(LanguageStatus::class)],
        ];
    }
}
