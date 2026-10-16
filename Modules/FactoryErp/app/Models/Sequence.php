<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Order numbers per kind and year.
 */
#[Fillable(['organization_id', 'kind', 'year', 'next'])]
class Sequence extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fac_sequences';

    protected function casts(): array
    {
        return ['year' => 'integer', 'next' => 'integer'];
    }
}
