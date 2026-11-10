<?php

namespace Modules\Patients\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Patients\Models\Patient;

/**
 * Registering or changing a patient. A birth date or an age (years) is
 * enough; the mobile may be typed in any usual form. confirm_new makes the
 * record even when look-alikes were shown. An op id when made, the version
 * when changed.
 */
class PatientRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:150'],
            'name_local' => ['sometimes', 'nullable', 'string', 'max:150'],
            'sex' => [$creating ? 'required' : 'sometimes', 'in:'.implode(',', Patient::SEXES)],
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before_or_equal:today'],
            'age_years' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:130'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:20'],
            'id_type' => ['required_with:id_number', 'in:'.implode(',', Patient::ID_TYPES)],
            'id_number' => ['sometimes', 'nullable', 'string', 'max:40'],
            'guardian_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'guardian_relation' => ['sometimes', 'nullable', 'string', 'max:30'],
            'blood_group' => ['sometimes', 'nullable', 'in:'.implode(',', Patient::BLOOD_GROUPS)],
            'allergies' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'address' => ['sometimes', 'nullable', 'string', 'max:300'],
            'confirm_new' => [$creating ? 'sometimes' : 'prohibited', 'boolean'],
            'op_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
