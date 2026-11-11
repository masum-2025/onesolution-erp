<?php

namespace Modules\Inpatient\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
/** A nurse's note on a stay: vitals, what was seen, the medicines given. Append-only. */
#[Fillable(['organization_id', 'admission_id', 'vitals', 'note', 'medicines_given', 'noted_by', 'noted_at', 'op_id'])]
class NursingNote extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    protected $table = 'inpatient_nursing_notes';

    protected function casts(): array
    {
        return ['vitals' => 'array', 'medicines_given' => 'array', 'noted_at' => 'datetime'];
    }
}
