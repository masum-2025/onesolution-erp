<?php

namespace Modules\Education\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A program's subjects per level, from a year on (batches follow it).
 */
#[Fillable(['organization_id', 'program_id', 'name', 'effective_from_year', 'status', 'version'])]
class Curriculum extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['draft', 'active', 'retired'];

    protected $table = 'edu_curricula';

    protected function casts(): array
    {
        return [
            'effective_from_year' => 'integer',
            'version' => 'integer',
        ];
    }
}
