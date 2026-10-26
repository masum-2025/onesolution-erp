<?php

namespace Modules\Expenses\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** One step's decision on a claim (approved or rejected), by whom, with a note. */
#[Fillable(['organization_id', 'claim_id', 'step', 'user_id', 'decision', 'note'])]
class Approval extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'exp_approvals';

    protected function casts(): array
    {
        return ['step' => 'integer'];
    }
}
