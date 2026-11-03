<?php

namespace Modules\Payroll\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Who approved an increment round at which level (each level a different person). */
#[Fillable(['organization_id', 'round_id', 'level', 'user_id'])]
class IncrementApproval extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'pay_increment_approvals';

    protected function casts(): array
    {
        return ['level' => 'integer'];
    }
}
