<?php

namespace App\Platform\Packaging\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A plan price: integer minor units in one currency for one period.
 */
#[Table('plan_prices')]
#[Fillable(['plan_id', 'currency_code', 'period', 'amount_minor'])]
class PlanPrice extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
