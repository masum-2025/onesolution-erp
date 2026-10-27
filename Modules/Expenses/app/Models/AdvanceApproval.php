<?php

namespace Modules\Expenses\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A decision on one step of an advance: who, approved or rejected, and a note. */
#[Fillable(['organization_id', 'advance_id', 'step', 'user_id', 'decision', 'note'])]
class AdvanceApproval extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'exp_advance_approvals';

    protected function casts(): array
    {
        return ['step' => 'integer'];
    }
}
