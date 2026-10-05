<?php

namespace Modules\Pos\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** How a sale was paid (or a return paid back): cash, card, mobile wallet. */
#[Fillable(['organization_id', 'sale_id', 'method', 'amount_minor', 'reference'])]
class Payment extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public $timestamps = false;

    protected $table = 'pos_payments';

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }
}
