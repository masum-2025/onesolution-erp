<?php

namespace App\Platform\Marketing\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/** A picture's name (for finding it) and the words read out instead of it, per language. */
class UpdateMediaRequest extends StrictFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $codes = LanguageRegistry::codes();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'alt' => ['sometimes', 'nullable', 'array:'.implode(',', $codes)],
            ...collect($codes)->mapWithKeys(fn ($code) => ["alt.{$code}" => ['nullable', 'string', 'max:200']])->all(),
        ];
    }
}
