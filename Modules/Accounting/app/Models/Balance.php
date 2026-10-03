<?php

namespace Modules\Accounting\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Posted totals of one account in one period and cost centre. Written only
 * by Posting, in the posting's transaction; reports add these up instead of
 * reading every line.
 */
#[Fillable(['organization_id', 'account_id', 'period_id', 'cost_centre_id', 'debit_minor', 'credit_minor'])]
class Balance extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'acc_balances';

    protected function casts(): array
    {
        return ['debit_minor' => 'integer', 'credit_minor' => 'integer'];
    }
}
