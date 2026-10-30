<?php

namespace Modules\Projects\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** Accepting a client's renewal request: the project's name, template, manager and days (the start day is told to the client). */
class AcceptRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'min:2', 'max:200'],
            'template_id' => ['nullable', 'string', 'size:26'],
            'manager_id' => ['nullable', 'string', 'size:26'],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ];
    }
}
