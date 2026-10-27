<?php

namespace Modules\Expenses\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;
use Modules\Expenses\Services\Categories;

/** A kind of expense: a short code, its name per language, the posting key it goes to, by the kilometre or not; the version seen when changing. */
class CategoryRequest extends StrictFormRequest
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
            'posting_key' => ['required', 'string', 'in:'.implode(',', Categories::POSTING_KEYS)],
            'mileage' => ['sometimes', 'boolean'],
            'per_diem' => ['sometimes', 'boolean'],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
        ];
    }
}
