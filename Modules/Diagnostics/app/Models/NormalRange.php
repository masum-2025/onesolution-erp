<?php

namespace Modules\Diagnostics\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The normal (and critical) limits of a parameter for a sex and an age band
 * in days (newborns differ from adults). Limits are decimals kept as strings.
 */
#[Fillable(['organization_id', 'parameter_id', 'sex', 'age_from_days', 'age_to_days', 'low', 'high', 'critical_low', 'critical_high', 'normal_text'])]
class NormalRange extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const SEXES = ['any', 'male', 'female'];

    protected $table = 'diagnostics_ranges';

    protected function casts(): array
    {
        return ['age_from_days' => 'integer', 'age_to_days' => 'integer'];
    }
}
