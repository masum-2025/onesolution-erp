<?php

namespace App\Platform\SupportAccess\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use App\Platform\SupportAccess\Enums\Severity;
use Illuminate\Validation\Rule;

class RequestSupportRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'string', 'ulid'],
            // The client reads this before deciding: say what you need to look at and why.
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'severity' => ['required', Rule::enum(Severity::class)],
            // Capped again by the client's support.max_duration_minutes.
            'minutes' => ['required', 'integer', 'min:15', 'max:480'],
        ];
    }
}
