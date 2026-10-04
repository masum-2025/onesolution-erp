<?php

namespace Modules\Accounting\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A posted journal line set against a statement line, with what it moved
 * on the account (debit minus credit: money in above zero).
 */
#[Fillable(['organization_id', 'bank_line_id', 'journal_line_id', 'amount_minor', 'created_by'])]
class BankMatch extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'acc_bank_matches';

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
