<?php

namespace Modules\Projects\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** A note on a task. */
class NoteRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }
}
