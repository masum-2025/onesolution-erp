<?php

namespace Modules\Payroll\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Who approved a run at which level (each level a different person). */
#[Fillable(['organization_id', 'run_id', 'level', 'user_id'])]
class RunApproval extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'pay_run_approvals';

    protected function casts(): array
    {
        return ['level' => 'integer'];
    }
}
