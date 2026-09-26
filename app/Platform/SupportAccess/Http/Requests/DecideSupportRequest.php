<?php

namespace App\Platform\SupportAccess\Http\Requests;

use App\Platform\SupportAccess\Http\Requests\SupportReasonRequest as Base;

/**
 * Approving support access: a note is optional (rejecting or ending needs a reason).
 */
class DecideSupportRequest extends Base
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['sometimes', 'nullable', 'string', 'max:500']];
    }
}
