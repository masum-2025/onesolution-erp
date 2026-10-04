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
 * The closing period (is_closing, number 13, the year's last day) holds only
 * the year's closing entry; it is never open and reports of profit leave it out.
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
            'is_closing' => 'boolean',
        ];
    }
}
