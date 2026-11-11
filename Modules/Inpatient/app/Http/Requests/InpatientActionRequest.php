<?php

namespace Modules\Inpatient\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Inpatient\Models\Bed;

/**
 * Inpatient writes by route name: beds, a bed's state by hand, admitting
 * (with an op id for retries), moving a patient and cancelling an admission.
 * The company and unit come from the address, never from the body.
 */
class InpatientActionRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $bed = [
            'ward_id' => ['string', 'size:26'],
            'code' => ['string', 'max:20', 'regex:/^[A-Za-z0-9\- ]+$/'],
            'service_code' => ['string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        return match ($this->route()?->getName()) {
            'inpatient.beds.store' => array_map(fn ($rules) => in_array('sometimes', $rules, true) ? $rules : ['required', ...$rules], $bed),
            'inpatient.beds.update' => [...array_map(fn ($rules) => in_array('sometimes', $rules, true) ? $rules : ['sometimes', ...$rules], $bed), 'base_version' => ['required', 'integer', 'min:1']],
            'inpatient.beds.status' => ['status' => ['required', 'in:'.implode(',', [Bed::FREE, Bed::BLOCKED])], 'base_version' => ['required', 'integer', 'min:1']],
            'inpatient.admissions.store' => [
                'patient_id' => ['required', 'string', 'size:26'],
                'bed_id' => ['required', 'string', 'size:26'],
                'doctor_user_id' => ['required', 'string', 'size:26'],
                'reason' => ['nullable', 'string', 'max:500'],
                'guardian_name' => ['nullable', 'string', 'max:100'],
                'guardian_mobile' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\- ]+$/'],
                'op_id' => ['nullable', 'string', 'max:64'],
            ],
            'inpatient.admissions.move' => [
                'bed_id' => ['required', 'string', 'size:26'],
                'reason' => ['nullable', 'string', 'max:300'],
                'base_version' => ['required', 'integer', 'min:1'],
            ],
            'inpatient.admissions.cancel' => ['reason' => ['required', 'string', 'min:3', 'max:300'], 'base_version' => ['required', 'integer', 'min:1']],
            default => [],
        };
    }
}
