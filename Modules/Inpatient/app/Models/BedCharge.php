<?php

namespace Modules\Inpatient\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
/**
 * One bed day of a stay put on the patient's bill (day 1, 2, …), at the
 * price of the bed the patient was in when the day began. Append-only; one
 * row a day of a stay.
 */
#[Fillable(['organization_id', 'admission_id', 'day_no', 'day_on', 'bed_id', 'service_code', 'bill_id', 'bill_line_id'])]
class BedCharge extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    protected $table = 'inpatient_bed_charges';

    protected function casts(): array
    {
        return ['day_no' => 'integer', 'day_on' => 'date:Y-m-d', 'withdrawn_at' => 'datetime'];
    }
}
