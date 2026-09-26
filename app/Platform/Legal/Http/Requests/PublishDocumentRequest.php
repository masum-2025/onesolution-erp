<?php

namespace App\Platform\Legal\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A new version of a partner's legal document: plain text per language
 * ("# " starts a heading, a blank line a paragraph). English is required.
 */
class PublishDocumentRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $locales = implode(',', (array) config('tenancy.supported_locales'));

        return [
            'title' => ['required', "array:{$locales}"],
            'title.en' => ['required', 'string', 'min:3', 'max:150'],
            'title.*' => ['nullable', 'string', 'max:150'],
            'body' => ['required', "array:{$locales}"],
            'body.en' => ['required', 'string', 'min:20', 'max:50000'],
            'body.*' => ['nullable', 'string', 'max:50000'],
            // What changed, shown to clients asked to accept it.
            'summary' => ['required', 'string', 'min:5', 'max:500'],
            // platform = the default for every partner (the house partner's owners only).
            'scope' => ['sometimes', 'string', 'in:partner,platform'],
        ];
    }
}
