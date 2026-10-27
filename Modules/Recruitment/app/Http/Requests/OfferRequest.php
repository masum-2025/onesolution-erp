<?php

namespace Modules\Recruitment\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** A job offer: the salary in minor units, the first day, the day to answer by; the version seen when changing a draft. */
class OfferRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => [$this->isMethod('post') ? 'prohibited' : 'required', 'integer', 'min:1'],
            'salary_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'start_on' => ['required', 'date_format:Y-m-d'],
            'respond_by' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
