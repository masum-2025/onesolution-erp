<?php

namespace Modules\Appraisals\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/** A competency: a short code and its name per language; in use or not; the version seen when changing. */
class CompetencyRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'array:'.implode(',', LanguageRegistry::codes())],
            'name.en' => ['required', 'string', 'max:80'],
            'name.*' => ['nullable', 'string', 'max:80'],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
        ];
    }
}
