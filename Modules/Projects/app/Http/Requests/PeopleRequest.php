<?php

namespace Modules\Projects\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** All the people of a task (none to leave it unassigned), and who leads it. */
class PeopleRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'user_ids' => ['present', 'array', 'max:50'],
            'user_ids.*' => ['string', 'size:26'],
            'lead_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
