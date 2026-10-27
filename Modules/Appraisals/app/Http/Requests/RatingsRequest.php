<?php

namespace Modules\Appraisals\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** One side's ratings (a value of the scale and a comment each) and summary; the version seen. */
class RatingsRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'ratings' => ['present', 'array', 'max:60'],
            'ratings.*.id' => ['required', 'string', 'size:26'],
            'ratings.*.rating' => ['nullable', 'integer', 'min:1', 'max:10'],
            'ratings.*.comment' => ['nullable', 'string', 'max:2000'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
