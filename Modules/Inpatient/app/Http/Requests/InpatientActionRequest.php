<?php

namespace Modules\Inpatient\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Inpatient\Models\Bed;
use Modules\Inpatient\Models\Discharge;

/**
 * Inpatient writes by route name: beds, a bed's state by hand, admitting
 * (with an op id for retries), moving a patient, cancelling an admission,
 * round and nursing notes, test orders and the discharge summary.
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
            'inpatient.rounds.store' => ['note' => ['required', 'string', 'min:2', 'max:4000'], 'op_id' => ['nullable', 'string', 'max:64']],
            'inpatient.nursing.store' => [
                'vitals' => ['nullable', 'array:bp_systolic,bp_diastolic,pulse,temperature,spo2,resp_rate,sugar'],
                'vitals.bp_systolic' => ['nullable', 'integer', 'between:40,300'],
                'vitals.bp_diastolic' => ['nullable', 'integer', 'between:20,200'],
                'vitals.pulse' => ['nullable', 'integer', 'between:20,250'],
                'vitals.temperature' => ['nullable', 'numeric', 'between:90,110', 'decimal:0,1'], // °F, as Bangladeshi wards chart it
                'vitals.spo2' => ['nullable', 'integer', 'between:50,100'],
                'vitals.resp_rate' => ['nullable', 'integer', 'between:4,80'],
                'vitals.sugar' => ['nullable', 'numeric', 'between:1,40', 'decimal:0,1'], // mmol/L
                'note' => ['nullable', 'string', 'max:2000'],
                'medicines_given' => ['nullable', 'array', 'max:20'],
                'medicines_given.*' => ['array:name,dose,route'],
                'medicines_given.*.name' => ['required', 'string', 'max:150'],
                'medicines_given.*.dose' => ['nullable', 'string', 'max:60'],
                'medicines_given.*.route' => ['nullable', 'in:oral,iv,im,sc,inhaled,topical,other'],
                'op_id' => ['nullable', 'string', 'max:64'],
            ],
            'inpatient.tests.store' => [
                'codes' => ['required', 'array', 'min:1', 'max:30'],
                'codes.*' => ['string', 'max:30', 'distinct'],
                'op_id' => ['nullable', 'string', 'max:64'],
            ],
            'inpatient.discharges.save' => [...$this->summary(), 'base_version' => ['nullable', 'integer', 'min:1']],
            'inpatient.discharges.finish' => ['base_version' => ['required', 'integer', 'min:1']],
            'inpatient.discharges.amend' => [...$this->summary(), 'diagnosis' => ['required', 'string', 'max:4000'], 'reason' => ['required', 'string', 'min:3', 'max:300']],
            default => [],
        };
    }

    /** @return array<string, list<string>> The discharge summary's fields. */
    private function summary(): array
    {
        return [
            'diagnosis' => ['nullable', 'string', 'max:4000'],
            'treatment' => ['nullable', 'string', 'max:4000'],
            'advice' => ['nullable', 'string', 'max:4000'],
            'follow_up_on' => ['nullable', 'date_format:Y-m-d'],
            'outcome' => ['nullable', 'in:'.implode(',', Discharge::OUTCOMES)],
        ];
    }
}
