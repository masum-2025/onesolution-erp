<?php

namespace Modules\TeacherFeedback\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Modules\TeacherFeedback\Models\Concerns\HasUntimedUlids;

/**
 * That someone (a keyed hash, never who) rated a teacher for a subject in
 * a round: only to stop a second rating and to count responses.
 */
#[Fillable(['organization_id', 'round_id', 'rater_hash', 'section_id', 'teacher_id', 'subject_id'])]
class Rater extends Model
{
    use BelongsToOrganization, HasUntimedUlids, UsesTenantDatabase;

    public $timestamps = false;

    protected $table = 'tfb_raters';
}
