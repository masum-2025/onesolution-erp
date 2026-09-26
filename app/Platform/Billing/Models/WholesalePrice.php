<?php

namespace App\Platform\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * What we charge a wholesale partner per month for a client on a plan.
 * partner_id null = the default for every partner. Rows are never edited: a
 * new row with a later effective_from replaces a price.
 */
#[Table('wholesale_prices')]
#[Fillable(['plan_key', 'currency_code', 'unit', 'amount_minor', 'effective_from'])]
class WholesalePrice extends Model
{
    use HasUlids;

    public const PER_CLIENT = 'per_client';

    public const PER_SEAT = 'per_seat';

    public const UNITS = [self::PER_CLIENT, self::PER_SEAT];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'effective_from' => 'immutable_date',
        ];
    }
}
