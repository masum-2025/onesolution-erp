<?php

namespace Modules\Projects\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A task of a project, for one or more people (prj_task_assignees). */
#[Fillable(['organization_id', 'project_id', 'title', 'description', 'status', 'priority', 'due_on', 'sort_order', 'done_at', 'done_by', 'overdue_told_for', 'op_id', 'created_by', 'version'])]
class Task extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const TODO = 'todo';

    public const DOING = 'doing';

    public const BLOCKED = 'blocked';

    public const DONE = 'done';

    public const STATUSES = [self::TODO, self::DOING, self::BLOCKED, self::DONE];

    public const PRIORITIES = ['low', 'normal', 'high'];

    protected $table = 'prj_tasks';

    protected function casts(): array
    {
        return [
            'due_on' => 'immutable_date',
            'done_at' => 'immutable_datetime',
            'overdue_told_for' => 'immutable_date',
            'sort_order' => 'integer',
            'version' => 'integer',
        ];
    }
}
