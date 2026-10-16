<?php

namespace Modules\TeacherFeedback\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Modules\TeacherFeedback\Models\Concerns\HasUntimedUlids;

/**
 * Stars for one question about a teacher and subject in a round. No rater,
 * no time: nothing ties it to who gave it.
 */
#[Fillable(['organization_id', 'round_id', 'section_id', 'teacher_id', 'subject_id', 'question_id', 'stars'])]
class Answer extends Model
{
    use BelongsToOrganization, HasUntimedUlids, UsesTenantDatabase;

    public $timestamps = false;

    protected $table = 'tfb_answers';

    protected function casts(): array
    {
        return ['stars' => 'integer'];
    }
}
