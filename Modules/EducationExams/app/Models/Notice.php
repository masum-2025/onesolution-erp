<?php

namespace Modules\EducationExams\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A result told to a family (once when published, or again once a day): how many messages, never to whom.
 */
#[Fillable(['organization_id', 'student_id', 'result_id', 'kind', 'once_key', 'sent_on', 'messages', 'sent_by'])]
class Notice extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'exm_notices';

    protected function casts(): array
    {
        return ['sent_on' => 'immutable_date', 'messages' => 'integer'];
    }
}
