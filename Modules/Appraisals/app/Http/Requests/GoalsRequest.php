<?php

namespace Modules\Appraisals\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** A review's goals sent whole: each with its id when kept, a title, a description and its weight (percent); the version seen. */
class GoalsRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'goals' => ['present', 'array', 'max:15'],
            'goals.*.id' => ['nullable', 'string', 'size:26'],
            'goals.*.title' => ['required', 'string', 'min:2', 'max:200'],
            'goals.*.description' => ['nullable', 'string', 'max:2000'],
            'goals.*.weight' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
