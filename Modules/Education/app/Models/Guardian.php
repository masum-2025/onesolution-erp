<?php

namespace Modules\Education\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A parent or guardian, shared by their children at the institution (one
 * phone, one guardian). The national id is encrypted at rest.
 */
#[Fillable(['organization_id', 'name', 'phone', 'email', 'occupation', 'national_id', 'user_id', 'extra', 'version'])]
class Guardian extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'edu_guardians';

    protected $hidden = ['national_id'];

    protected function casts(): array
    {
        return [
            'national_id' => 'encrypted',
            'extra' => 'array',
            'version' => 'integer',
        ];
    }
}
