<?php

namespace Modules\Education\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A promotion list: the students of a session's level (or one section) and
 * what happens to each in the next session. draft -> pending_approval (rule
 * education.promotion_approval) -> applied -> undone; cancelled while a draft.
 */
#[Fillable([
    'organization_id', 'unit_id', 'number', 'from_session_id', 'to_session_id', 'level_id', 'section_id', 'status', 'note',
    'created_by', 'submitted_by', 'approved_by', 'applied_at', 'undo_until', 'undone_at', 'undone_by', 'version',
])]
class PromotionBatch extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['draft', 'pending_approval', 'applied', 'undone', 'cancelled'];

    protected $table = 'edu_promotion_batches';

    protected function casts(): array
    {
        return [
            'applied_at' => 'immutable_datetime',
            'undo_until' => 'immutable_date',
            'undone_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
