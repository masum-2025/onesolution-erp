<?php

namespace Modules\Education\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * An application (POST) or a change to it while undecided (PATCH): the
 * place applied for and the applicant as given (guardians included).
 */
class AdmissionRequest extends StrictFormRequest
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
            'program_id' => [$required, 'string', 'size:26'],
            'level_id' => [$required, 'string', 'size:26'],
            'session_id' => [$required, 'string', 'size:26'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'applicant' => [$required, 'array:name,name_local,gender,date_of_birth,phone,email,guardians,extra,previous_school'],
            'applicant.name' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:150'],
            'applicant.name_local' => ['nullable', 'string', 'max:150'],
            'applicant.gender' => ['nullable', 'string', 'max:20'],
            'applicant.date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'applicant.phone' => ['nullable', 'string', 'max:30'],
            'applicant.email' => ['nullable', 'email:rfc', 'max:190'],
            'applicant.previous_school' => ['nullable', 'string', 'max:150'],
            'applicant.extra' => ['sometimes', 'array'],
            'applicant.guardians' => ['sometimes', 'array', 'max:6'],
            ...GuardianRequest::linkRules('applicant.guardians.*.'),
        ];
    }
}
