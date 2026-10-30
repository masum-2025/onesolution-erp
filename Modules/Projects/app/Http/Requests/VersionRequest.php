<?php

namespace Modules\Projects\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** Only the version seen (a change with nothing else to say). */
class VersionRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['base_version' => ['required', 'integer', 'min:1']];
    }
}
