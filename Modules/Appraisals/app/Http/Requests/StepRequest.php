<?php

namespace Modules\Appraisals\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** A step of a review: the version seen, and a note (a reason, needed when sending it back; the employee's comment when acknowledging). */
class StepRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'note' => [$this->route('step') === 'send_back' ? 'required' : 'nullable', 'string', 'max:2000'],
        ];
    }
}
