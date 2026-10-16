<?php

namespace Modules\EducationLectures\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A lecture a teacher posts for a subject of a section on a day: a title,
 * plain text, a video link and files. Draft, waiting for approval,
 * published, or withdrawn with a reason (a published lecture is never deleted).
 */
#[Fillable(['organization_id', 'unit_id', 'session_id', 'section_id', 'subject_id', 'teacher_id', 'posted_by', 'lesson_on', 'title', 'body', 'video_url', 'status', 'published_at', 'approved_by', 'withdrawn_by', 'withdrawn_reason', 'op_id', 'version'])]
class Lecture extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['draft', 'waiting', 'published', 'withdrawn'];

    protected $table = 'lec_lectures';

    protected $attributes = ['status' => 'draft', 'version' => 1];

    protected function casts(): array
    {
        return ['lesson_on' => 'immutable_date', 'published_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
