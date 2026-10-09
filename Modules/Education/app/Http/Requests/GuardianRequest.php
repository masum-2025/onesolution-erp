<?php

namespace Modules\Education\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A guardian linked to a student (POST: one already here by id or phone, or
 * a new one) or a guardian's details changed (PATCH).
 */
class GuardianRequest extends StrictFormRequest
{
    /**
     * Rules of one guardian link, also used for the guardians given with a new student.
     *
     * @return array<string, mixed>
     */
    public static function linkRules(string $prefix = ''): array
    {
        return [
            "{$prefix}guardian_id" => ['nullable', 'string', 'size:26'],
            "{$prefix}name" => ['nullable', 'string', 'max:150'],
            "{$prefix}phone" => ['nullable', 'string', 'max:30'],
            "{$prefix}email" => ['nullable', 'email:rfc', 'max:190'],
            "{$prefix}occupation" => ['nullable', 'string', 'max:100'],
            "{$prefix}national_id" => ['nullable', 'string', 'max:40'],
            "{$prefix}relation" => ['required', 'string', 'max:40'],
            "{$prefix}is_primary" => ['sometimes', 'boolean'],
            "{$prefix}can_pick_up" => ['sometimes', 'boolean'],
            "{$prefix}receives_notices" => ['sometimes', 'boolean'],
            "{$prefix}extra" => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->isMethod('post')) {
            return self::linkRules();
        }

        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'min:1', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'occupation' => ['sometimes', 'nullable', 'string', 'max:100'],
            'national_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'extra' => ['sometimes', 'array'],
        ];
    }
}
