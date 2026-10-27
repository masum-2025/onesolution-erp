<?php

namespace Modules\Recruitment\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** Ending an application (rejected or withdrawn): the version seen, why (kept inside the company), and whether to tell the person. */
class EndRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:2', 'max:500'],
            'tell' => ['sometimes', 'boolean'],
        ];
    }
}
