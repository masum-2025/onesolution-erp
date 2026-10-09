<?php

namespace Modules\Education\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A student made directly (with guardians and a first enrollment) or
 * changed. Sensitive details may be sent only by people allowed to see them
 * (checked by the controller). Own fields go under "extra".
 */
class StudentRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'op_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'unit_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'size:26'],
            'name' => [$required, 'string', 'min:1', 'max:150'],
            'name_local' => ['sometimes', 'nullable', 'string', 'max:150'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:20'],
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
            'birth_registration_no' => ['sometimes', 'nullable', 'string', 'max:40'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'program_id' => [$creating ? 'required' : 'sometimes', 'string', 'size:26'],
            'batch_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'category_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'admission_no' => ['sometimes', 'nullable', 'string', 'max:40'],
            'admitted_on' => [$creating ? 'nullable' : 'prohibited', 'date_format:Y-m-d'],
            'extra' => ['sometimes', 'array'],
            'guardians' => [$creating ? 'sometimes' : 'prohibited', 'array', 'max:6'],
            ...GuardianRequest::linkRules('guardians.*.'),
            // Left out or null: the student is placed later.
            'enrollment' => [$creating ? 'sometimes' : 'prohibited', 'nullable', 'array:session_id,level_id,section_id'],
            'enrollment.session_id' => ['required_with:enrollment', 'string', 'size:26'],
            'enrollment.level_id' => ['required_with:enrollment', 'string', 'size:26'],
            'enrollment.section_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
