<?php

namespace Modules\Projects\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** One of the people a task is for; one of them may lead it. */
#[Fillable(['organization_id', 'task_id', 'user_id', 'is_lead', 'assigned_by', 'created_at'])]
class TaskAssignee extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    protected $table = 'prj_task_assignees';

    protected function casts(): array
    {
        return ['is_lead' => 'boolean', 'created_at' => 'immutable_datetime'];
    }
}
