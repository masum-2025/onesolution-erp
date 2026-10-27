<?php

namespace Modules\Recruitment\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** A step of an offer: the version seen, and a note (needed when sending it back to its writer). */
class OfferStepRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'note' => [$this->route('step') === 'return' ? 'required' : 'nullable', 'string', 'max:500'],
        ];
    }
}
