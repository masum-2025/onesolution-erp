<?php

namespace Modules\Education\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One student's decision in a promotion list, and the enrollment it made.
 */
#[Fillable(['organization_id', 'batch_id', 'student_id', 'from_enrollment_id', 'decision', 'to_level_id', 'to_section_id', 'reason', 'repeats', 'result_enrollment_id'])]
class PromotionLine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const DECISIONS = ['promote', 'repeat', 'leave', 'graduate'];

    protected $table = 'edu_promotion_lines';

    protected function casts(): array
    {
        return ['repeats' => 'integer'];
    }
}
