<?php

namespace App\Platform\Localization\Http\Requests;

use App\Platform\Localization\Enums\Channel;
use App\Platform\Localization\Services\TranslationService;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

/**
 * A translator's file, already read by the browser: [{key, value}, …].
 * Every text is checked before any is saved.
 */
class ImportTextsRequest extends StrictFormRequest
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
            'texts' => ['required', 'array', 'min:1', 'max:'.TranslationService::MAX_IMPORT],
            // A list, not a map: keys contain dots, which validation would read as nesting.
            'texts.*' => ['array:key,value'],
            'texts.*.key' => ['required', 'string', 'max:190'],
            'texts.*.value' => ['nullable', 'string', 'max:'.TranslationService::MAX_LENGTH],
        ];
    }
}
