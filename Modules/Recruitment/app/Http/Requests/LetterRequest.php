<?php

namespace Modules\Recruitment\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/** The company's offer letter: its text per language (English needed), with the version seen (0 when it has none of its own yet). */
class LetterRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:0'],
            'body' => ['required', 'array:'.implode(',', LanguageRegistry::codes())],
            'body.en' => ['required', 'string', 'min:20', 'max:10000'],
            'body.*' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
