<?php

namespace Modules\Compliance\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Cancelling a renewal request, or accepting or rejecting a provider's
 * result: the version seen, and a reason (needed to reject).
 */
class DecisionRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            // Rejecting a result and declining a quotation say why.
            'reason' => [in_array($this->route('step'), ['reject', 'decline'], true) ? 'required' : 'nullable', 'string', 'min:3', 'max:500'],
        ];
    }
}
