<?php

namespace Modules\Attendance\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A shift: code, name in every supported language, start and end as minutes
 * after midnight (an end at or before the start is the next day), break.
 */
class ShiftRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';
        $locales = (array) config('tenancy.supported_locales');

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'code' => [$required, 'string', 'regex:/^[A-Za-z0-9.\-_]{1,20}$/'],
            'name' => [$required, 'array:'.implode(',', $locales)],
            'name.en' => [$required, 'string', 'min:2', 'max:80'],
            'name.*' => ['nullable', 'string', 'max:80'],
            'start_minute' => [$required, 'integer', 'min:0', 'max:1439'],
            'end_minute' => [$required, 'integer', 'min:0', 'max:1439'],
            'break_minutes' => ['sometimes', 'integer', 'min:0', 'max:600'],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
        ];
    }
}
