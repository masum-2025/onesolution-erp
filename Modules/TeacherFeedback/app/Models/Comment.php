<?php

namespace Modules\TeacherFeedback\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Modules\TeacherFeedback\Models\Concerns\HasUntimedUlids;

/**
 * Words about a teacher and subject in a round, without who wrote them or
 * when. The teacher sees it only once someone who manages approves it.
 */
#[Fillable(['organization_id', 'round_id', 'section_id', 'teacher_id', 'subject_id', 'text', 'status', 'decided_by'])]
class Comment extends Model
{
    use BelongsToOrganization, HasUntimedUlids, UsesTenantDatabase;

    public const STATUSES = ['pending', 'approved', 'hidden'];

    public $timestamps = false;

    protected $table = 'tfb_comments';

    protected $attributes = ['status' => 'pending'];
}
