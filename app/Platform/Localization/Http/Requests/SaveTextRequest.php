<?php

namespace App\Platform\Localization\Http\Requests;

use App\Platform\Localization\Enums\Channel;
use App\Platform\Localization\Services\TranslationService;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

/**
 * One text: which language, browser or server, which key, and the wording
 * ("value" only when saving). "level" is the partner console's platform /
 * partner choice; an organization's level is the organization itself.
 */
class SaveTextRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $saving = $this->isMethod('put');

        return [
            'level' => ['sometimes', 'string', Rule::in(['platform', 'partner'])],
            'locale' => ['required', 'string', 'max:20'],
            'channel' => ['required', Rule::enum(Channel::class)],
            'key' => ['required', 'string', 'max:190'],
            'value' => $saving ? ['required', 'string', 'max:'.TranslationService::MAX_LENGTH] : ['prohibited'],
        ];
    }
}
