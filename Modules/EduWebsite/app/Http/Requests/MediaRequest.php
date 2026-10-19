<?php

namespace Modules\EduWebsite\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** A picture or PDF for the website (kind and size are checked from the file itself). */
class MediaRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['file' => ['required', 'file']];
    }
}
