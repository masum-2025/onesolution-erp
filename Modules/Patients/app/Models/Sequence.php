<?php

namespace Modules\Patients\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The last patient number given in a company (one counter per kind).
 */
#[Fillable(['organization_id', 'kind', 'last_number'])]
class Sequence extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'patients_sequences';

    protected function casts(): array
    {
        return ['last_number' => 'integer'];
    }
}
