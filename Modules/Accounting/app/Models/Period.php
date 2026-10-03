<?php

namespace Modules\Accounting\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Enums\PeriodStatus;

/**
 * One month of a fiscal year. Entries are posted only into open periods.
 */
#[Fillable(['organization_id', 'fiscal_year_id', 'number', 'starts_on', 'ends_on', 'status', 'closed_by', 'closed_at'])]
class Period extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'acc_periods';

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'status' => PeriodStatus::class,
            'closed_at' => 'immutable_datetime',
            'number' => 'integer',
        ];
    }
}
