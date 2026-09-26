<?php

namespace App\Platform\Packaging\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A partner plan's price: integer minor units in one currency for one period.
 */
#[Table('partner_plan_prices')]
#[Fillable(['currency_code', 'period', 'amount_minor'])]
class PartnerPlanPrice extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
