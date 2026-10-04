<?php

namespace Modules\Accounting\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A statement date and balance that matched the books: the statement lines
 * up to that day are locked. The latest one may be reopened with a reason.
 */
#[Fillable(['organization_id', 'account_id', 'statement_date', 'opening_balance_minor', 'statement_balance_minor', 'book_balance_minor', 'status', 'finished_by', 'finished_at'])]
class Reconciliation extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const FINISHED = 'finished';

    public const REOPENED = 'reopened';

    protected $table = 'acc_reconciliations';

    protected function casts(): array
    {
        return [
            'statement_date' => 'immutable_date',
            'opening_balance_minor' => 'integer',
            'statement_balance_minor' => 'integer',
            'book_balance_minor' => 'integer',
            'finished_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
        ];
    }
}
