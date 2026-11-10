<?php

namespace Modules\Diagnostics\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Diagnostics\Models\Delivery;

/**
 * What each lab step takes, by route name: results (parameter id => value,
 * with the test's version), verifying (an optional note), sending back or
 * rejecting a sample (a reason), handing over the report (how).
 */
class LabActionRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'diagnostics.results.save' => [
                'values' => ['present', 'array', 'max:100'],
                'values.*' => ['nullable', 'string', 'max:200'],
                'base_version' => ['required', 'integer', 'min:1'],
            ],
            'diagnostics.results.verify' => ['note' => ['nullable', 'string', 'max:300']],
            'diagnostics.results.send_back', 'diagnostics.samples.reject' => ['reason' => ['required', 'string', 'min:3', 'max:300']],
            'diagnostics.orders.deliver' => ['channel' => ['required', 'in:'.implode(',', Delivery::CHANNELS)]],
            default => [],
        };
    }
}
