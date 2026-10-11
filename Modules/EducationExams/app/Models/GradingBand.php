<?php

namespace Modules\EducationExams\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * From a percentage (basis points) up: a letter, a grade point (hundredths), pass or fail.
 */
#[Fillable(['organization_id', 'scheme_id', 'min_percent_bp', 'letter', 'point_centi', 'is_pass'])]
class GradingBand extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'exm_grading_bands';

    protected function casts(): array
    {
        return ['min_percent_bp' => 'integer', 'point_centi' => 'integer', 'is_pass' => 'boolean'];
    }
}
