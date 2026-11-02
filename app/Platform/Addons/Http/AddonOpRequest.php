<?php

namespace App\Platform\Addons\Http;

use App\Platform\Billing\Http\Requests\SelfServePlanRequest;
use App\Platform\Support\Http\StrictFormRequest;

/** Starting an add-on (trial or buy): one click, one op_id. */
class AddonOpRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return SelfServePlanRequest::opRule();
    }
}
