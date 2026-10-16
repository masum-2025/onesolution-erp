<?php

namespace Modules\TeacherFeedback\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Making or changing a feedback round: a name, the session (fixed once
 * made), the dates and the questions (each in one or more languages).
 * What may change once open is checked by Rounds.
 */
class RoundRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:3', 'max:100'],
            'session_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'],
            'opens_on' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'closes_on' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'questions' => ['sometimes', 'array', 'min:1', 'max:12'],
            'questions.*' => ['required', 'array', 'min:1'],
            'questions.*.*' => ['required', 'string', 'min:2', 'max:200'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
