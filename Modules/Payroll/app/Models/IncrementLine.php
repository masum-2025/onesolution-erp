<?php

namespace Modules\Payroll\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One employee in an increment round: the basic now, the appraisal band,
 * the rate (from the rule for the band, or set by hand with a reason), the
 * new basic, left out or not; once applied, the salary it became.
 */
#[Fillable(['organization_id', 'round_id', 'employee_id', 'unit_id', 'employee_code', 'employee_name', 'salary_id', 'structure_id', 'current_minor',
    'score', 'band', 'rate_bp', 'rate_source', 'new_minor', 'reason', 'excluded'])]
class IncrementLine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    /** The rate comes from the rule for the band. */
    public const FROM_RULE = 'rule';

    /** The rate was set by a person (with a reason). */
    public const BY_HAND = 'hand';

    /** No band (no appraisal) and nothing set: no increment. */
    public const NONE = 'none';

    protected $table = 'pay_increment_lines';

    protected function casts(): array
    {
        return [
            'current_minor' => 'integer',
            'score' => 'integer',
            'rate_bp' => 'integer',
            'new_minor' => 'integer',
            'excluded' => 'boolean',
        ];
    }
}
