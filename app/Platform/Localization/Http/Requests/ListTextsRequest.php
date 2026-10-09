<?php

namespace App\Platform\Localization\Http\Requests;

use App\Platform\Localization\Enums\Channel;
use App\Platform\Localization\Services\TranslationEditor;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

/** Which texts the editor shows: language, channel, and the search. */
class ListTextsRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'level' => ['sometimes', 'string', Rule::in(['platform', 'partner'])],
            'locale' => ['required', 'string', 'max:20'],
            'channel' => ['required', Rule::enum(Channel::class)],
            'namespace' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', Rule::in(TranslationEditor::FILTERS)],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.TranslationEditor::MAX_PER_PAGE],
        ];
    }
}
