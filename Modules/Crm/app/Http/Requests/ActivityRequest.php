<?php

namespace Modules\Crm\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Crm\Models\Activity;

/**
 * A call, meeting, visit, note or follow-up made or changed (the version seen; done true or false).
 */
class ActivityRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'op_id' => [$creating ? 'sometimes' : 'prohibited', 'nullable', 'string', 'max:64'],
            'contact_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'],
            'deal_id' => [$creating ? 'sometimes' : 'prohibited', 'nullable', 'string', 'size:26'],
            'kind' => [$creating ? 'required' : 'sometimes', 'in:'.implode(',', Activity::KINDS)],
            'subject' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:150'],
            'body' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'assigned_to' => ['sometimes', 'nullable', 'string', 'size:26'],
            'done' => ['sometimes', 'boolean'],
        ];
    }
}
