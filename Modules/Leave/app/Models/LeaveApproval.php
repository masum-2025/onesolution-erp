<?php

namespace Modules\Leave\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** One approval step of a leave request: who decided and how. Never changed. */
#[Fillable(['organization_id', 'request_id', 'step', 'user_id', 'decision', 'note'])]
class LeaveApproval extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    protected $table = 'lv_approvals';

    protected function casts(): array
    {
        return ['step' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
