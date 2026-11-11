<?php

namespace Modules\Inpatient\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
/** A move from one bed to another during a stay (the first row is the admission itself). Append-only. */
#[Fillable(['organization_id', 'admission_id', 'from_bed_id', 'to_bed_id', 'moved_at', 'reason', 'moved_by'])]
class BedMove extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    protected $table = 'inpatient_bed_moves';

    protected function casts(): array
    {
        return ['moved_at' => 'datetime'];
    }
}
