<?php

namespace Modules\EducationFees\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A fee reminder sent (once per bill and step, or per student and day): how many messages, never to whom.
 */
#[Fillable(['organization_id', 'student_id', 'bill_id', 'kind', 'once_key', 'sent_on', 'messages', 'sent_by'])]
class Reminder extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fee_reminders';

    protected function casts(): array
    {
        return ['sent_on' => 'immutable_date', 'messages' => 'integer'];
    }
}
