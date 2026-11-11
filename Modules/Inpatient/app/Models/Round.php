<?php

namespace Modules\Inpatient\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
/** A doctor's round note on a stay. Append-only. */
#[Fillable(['organization_id', 'admission_id', 'doctor_user_id', 'note', 'noted_at', 'op_id'])]
class Round extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    protected $table = 'inpatient_rounds';

    protected function casts(): array
    {
        return ['noted_at' => 'datetime'];
    }
}
