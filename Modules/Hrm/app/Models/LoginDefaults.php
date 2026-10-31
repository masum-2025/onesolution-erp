<?php

namespace Modules\Hrm\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's first password for employees' logins (its hash only) and the
 * roles those logins get. Set on the HRM settings page; see EmployeeLogins.
 */
#[Fillable(['organization_id', 'role_ids'])]
#[Hidden(['password_hash'])]
class LoginDefaults extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'hrm_login_defaults';

    protected function casts(): array
    {
        return [
            'role_ids' => 'array',
            'password_set_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
