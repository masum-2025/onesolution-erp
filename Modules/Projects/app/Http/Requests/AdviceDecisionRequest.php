<?php

namespace Modules\Projects\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Answering a client's payment advice: "record" names the money account and
 * the day the money came in; "not-received" says why.
 */
class AdviceDecisionRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $record = $this->route('step') === 'record';

        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'account_id' => [$record ? 'required' : 'prohibited', 'string', 'size:26'],
            'settled_on' => [$record ? 'required' : 'prohibited', 'date_format:Y-m-d'],
            'reason' => [$record ? 'prohibited' : 'required', 'string', 'min:3', 'max:500'],
        ];
    }
}
