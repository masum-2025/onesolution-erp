<?php

namespace Modules\Recruitment\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Recruitment\Models\Interview;

/** An interview: when (the company's local time), how long, how, where (a place or a link), who interviews, and whether to tell the candidate. */
class InterviewRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'minutes' => ['required', 'integer', 'min:10', 'max:480'],
            'mode' => ['required', 'in:'.implode(',', Interview::MODES)],
            'place' => ['nullable', 'string', 'max:300'],
            'interviewer_ids' => ['required', 'array', 'min:1', 'max:10'],
            'interviewer_ids.*' => ['string', 'size:26'],
            'tell_candidate' => ['sometimes', 'boolean'],
        ];
    }
}
