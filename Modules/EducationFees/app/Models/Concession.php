<?php

namespace Modules\EducationFees\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A student's discount or scholarship: a percent (basis points) or a fixed amount, one head or all.
 */
#[Fillable(['organization_id', 'unit_id', 'student_id', 'head_id', 'mode', 'percent_bp', 'amount_minor', 'reason', 'starts_on', 'ends_on', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note', 'version'])]
class Concession extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['pending', 'active', 'rejected', 'ended'];

    protected $table = 'fee_concessions';

    protected function casts(): array
    {
        return ['percent_bp' => 'integer', 'amount_minor' => 'integer', 'starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'decided_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
