<?php

namespace Modules\Projects\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Projects\Models\Task;

/**
 * A task: title, description, priority and due day; when added, also its
 * people (one may lead) and an op_id when made offline. The version seen
 * when changing it.
 */
class TaskRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', 'in:'.implode(',', Task::PRIORITIES)],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'user_ids' => [$creating ? 'sometimes' : 'prohibited', 'array', 'max:50'],
            'user_ids.*' => ['string', 'size:26'],
            'lead_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'size:26'],
            'op_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
        ];
    }
}
