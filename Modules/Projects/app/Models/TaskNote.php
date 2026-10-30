<?php

namespace Modules\Projects\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A note on a task by someone working on it or managing it: kept, never changed. */
#[Fillable(['organization_id', 'task_id', 'user_id', 'body', 'created_at'])]
class TaskNote extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    protected $table = 'prj_task_notes';

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
