<?php

namespace App\Platform\Marketing\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * One picture for the website. 20 MB is only the outer bound: the size
 * allowed (rule marketing.max_media_mb) and the kind (read from the bytes)
 * are checked by MediaLibrary.
 */
class UploadMediaRequest extends StrictFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:20480'],
        ];
    }
}
