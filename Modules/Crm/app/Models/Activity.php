<?php

namespace Modules\Crm\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A call, meeting, note or follow-up with a contact; a task has a time and someone to do it.
 */
#[Fillable(['organization_id', 'unit_id', 'contact_id', 'deal_id', 'kind', 'subject', 'body', 'due_at', 'done_at', 'assigned_to', 'reminded_at', 'created_by', 'op_id', 'version'])]
class Activity extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const KINDS = ['call', 'meeting', 'visit', 'note', 'task', 'sms', 'email'];

    protected $table = 'crm_activities';

    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_datetime',
            'done_at' => 'immutable_datetime',
            'reminded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
