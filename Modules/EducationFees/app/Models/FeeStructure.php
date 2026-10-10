<?php

namespace Modules\EducationFees\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Amounts per head for a session, narrowed by campus, programme, class and student category.
 */
#[Fillable(['organization_id', 'name', 'session_id', 'unit_id', 'program_id', 'level_id', 'category_id', 'status', 'created_by', 'version'])]
class FeeStructure extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['draft', 'active', 'archived'];

    protected $table = 'fee_structures';

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
