<?php

namespace Modules\Inpatient\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
/**
 * A patient's stay: number, attending doctor (a staff member), bed, when they
 * came and why, the guardian. Admitted -> discharged; cancelled only before
 * any bed day is charged.
 */
#[Fillable(['organization_id', 'unit_id', 'number', 'patient_id', 'doctor_user_id', 'bed_id', 'admitted_at', 'reason', 'guardian_name', 'guardian_mobile', 'admitted_by', 'op_id'])]
class Admission extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const ADMITTED = 'admitted';

    public const DISCHARGED = 'discharged';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::ADMITTED, self::DISCHARGED, self::CANCELLED];

    protected $table = 'inpatient_admissions';

    protected $attributes = ['version' => 1, 'status' => self::ADMITTED];

    protected function casts(): array
    {
        return ['admitted_at' => 'datetime', 'discharged_at' => 'datetime', 'cancelled_at' => 'datetime', 'version' => 'integer'];
    }
}
