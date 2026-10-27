<?php

namespace Modules\Recruitment\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Recruitment\Models\Feedback;

/** An interviewer's feedback: a score of 1 to 5, yes / maybe / no, and a note. */
class FeedbackRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'recommendation' => ['required', 'in:'.implode(',', Feedback::RECOMMENDATIONS)],
            'note' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
