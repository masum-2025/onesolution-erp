<?php

namespace Modules\Recruitment\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** What an interviewer thinks of a candidate: a score of 1 to 5, yes / maybe / no, and a note (encrypted). One per interviewer and interview. */
#[Fillable(['organization_id', 'interview_id', 'application_id', 'user_id', 'score', 'recommendation', 'note'])]
class Feedback extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const RECOMMENDATIONS = ['yes', 'maybe', 'no'];

    protected $table = 'rec_feedback';

    protected $hidden = ['note'];

    protected function casts(): array
    {
        return ['score' => 'integer', 'note' => 'encrypted'];
    }
}
