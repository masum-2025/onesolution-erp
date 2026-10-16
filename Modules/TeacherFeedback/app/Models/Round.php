<?php

namespace Modules\TeacherFeedback\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A feedback round at a campus for a session: draft (questions can change),
 * open between its dates, closed. Who rates is fixed when it opens.
 */
#[Fillable(['organization_id', 'unit_id', 'session_id', 'name', 'opens_on', 'closes_on', 'status', 'who_rates', 'created_by', 'version'])]
class Round extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['draft', 'open', 'closed'];

    public const WHO = ['students', 'guardians', 'both'];

    protected $table = 'tfb_rounds';

    protected $attributes = ['status' => 'draft', 'version' => 1];

    protected function casts(): array
    {
        return ['opens_on' => 'immutable_date', 'closes_on' => 'immutable_date', 'version' => 'integer'];
    }
}
