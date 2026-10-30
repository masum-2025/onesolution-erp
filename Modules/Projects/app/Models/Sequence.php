<?php

namespace Modules\Projects\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** The last project number per company and year. */
#[Fillable(['organization_id', 'year', 'last_number'])]
class Sequence extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'prj_sequences';

    protected function casts(): array
    {
        return ['year' => 'integer', 'last_number' => 'integer'];
    }
}
