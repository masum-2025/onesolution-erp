<?php

namespace App\Platform\Addons\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A module's monthly add-on price. list: what a client pays (partner_id null
 * = the platform's price, else that partner's price for its own clients);
 * wholesale: what a wholesale partner pays us per client (partner_id null =
 * every partner). Rows are never edited: a newer effective_from replaces one.
 */
#[Table('addon_prices')]
#[Fillable(['module_key', 'kind', 'currency_code', 'amount_minor', 'effective_from'])]
class AddonPrice extends Model
{
    use HasUlids;

    public const LIST = 'list';

    public const WHOLESALE = 'wholesale';

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'effective_from' => 'immutable_date',
        ];
    }
}
