<?php

namespace Modules\Education\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An academic year (2026, 2025-26).
 */
#[Fillable(['organization_id', 'name', 'starts_on', 'ends_on', 'status', 'version'])]
class AcademicYear extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['planned', 'open', 'closed'];

    protected $table = 'edu_academic_years';

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'version' => 'integer',
        ];
    }
}
