<?php

namespace Modules\Projects\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Projects\Models\Task;

/** A task's new status, with the version seen (a change made offline may meet a newer one). */
class TaskStatusRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'in:'.implode(',', Task::STATUSES)],
        ];
    }
}
