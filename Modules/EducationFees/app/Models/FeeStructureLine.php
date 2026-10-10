<?php

namespace Modules\EducationFees\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One head's amount in a structure (and the months a monthly head is billed in).
 */
#[Fillable(['organization_id', 'structure_id', 'head_id', 'amount_minor', 'months'])]
class FeeStructureLine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fee_structure_lines';

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'months' => 'array'];
    }
}
