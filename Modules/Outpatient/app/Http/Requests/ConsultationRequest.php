<?php

namespace Modules\Outpatient\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Outpatient\Models\Consultation;
use Modules\Outpatient\Models\PrescriptionItem;

/**
 * What a doctor writes (a draft with its version, finishing it, an amendment
 * with a reason) and prescription templates, by route name. The serial,
 * patient and doctor come from the address, never from the body.
 */
class ConsultationRequest extends StrictFormRequest
{
    private const MAX_LINES = 40;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'outpatient.consultations.save' => [
                'base_version' => ['nullable', 'integer', 'min:1'],
                'complaints' => ['nullable', 'string', 'max:2000'],
                'findings' => ['nullable', 'array'],
                ...collect(Consultation::FINDINGS)->mapWithKeys(fn ($key) => ["findings.{$key}" => ['nullable', 'string', 'max:'.($key === 'other' ? 500 : 30)]])->all(),
                'diagnosis' => ['nullable', 'string', 'max:1000'],
                'advice' => ['nullable', 'string', 'max:2000'],
                'follow_up_on' => ['nullable', 'date_format:Y-m-d'],
                ...$this->lines(),
            ],
            'outpatient.consultations.finish' => ['base_version' => ['required', 'integer', 'min:1']],
            'outpatient.consultations.amend' => ['reason' => ['required', 'string', 'min:3', 'max:300']],
            'outpatient.templates.store', 'outpatient.templates.update' => [
                'name' => ['required', 'string', 'max:100'],
                'shared' => ['sometimes', 'boolean'],
                'base_version' => [$this->route()?->getName() === 'outpatient.templates.update' ? 'required' : 'prohibited', 'integer', 'min:1'],
                'body' => ['required', 'array'],
                'body.advice' => ['nullable', 'string', 'max:2000'],
                ...collect($this->lines())->mapWithKeys(fn ($rules, $key) => ["body.{$key}" => $rules])->all(),
            ],
            default => [],
        };
    }

    /** @return array<string, mixed> Medicines and tests, as in a consultation or a template. */
    private function lines(): array
    {
        return [
            'medicines' => ['nullable', 'array', 'max:'.self::MAX_LINES],
            'medicines.*.medicine_id' => ['nullable', 'string', 'size:26'],
            'medicines.*.medicine_text' => ['required', 'string', 'max:150'],
            'medicines.*.dose' => ['nullable', 'string', 'max:40'],
            'medicines.*.duration' => ['nullable', 'string', 'max:40'],
            'medicines.*.timing' => ['nullable', 'in:'.implode(',', PrescriptionItem::TIMINGS)],
            'medicines.*.note' => ['nullable', 'string', 'max:200'],
            'tests' => ['nullable', 'array', 'max:'.self::MAX_LINES],
            'tests.*.test_code' => ['nullable', 'string', 'max:30'],
            'tests.*.test_text' => ['required', 'string', 'max:150'],
        ];
    }
}
