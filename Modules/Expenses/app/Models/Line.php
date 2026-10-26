<?php

namespace Modules\Expenses\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** What was spent: the day, the kind, what for, the amount (or kilometres driven, in tenths, for a mileage kind). */
#[Fillable(['organization_id', 'claim_id', 'line_no', 'spent_on', 'category_id', 'description', 'amount_minor', 'distance_tenths'])]
class Line extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'exp_lines';

    protected function casts(): array
    {
        return ['spent_on' => 'immutable_date', 'line_no' => 'integer', 'amount_minor' => 'integer', 'distance_tenths' => 'integer'];
    }
}
