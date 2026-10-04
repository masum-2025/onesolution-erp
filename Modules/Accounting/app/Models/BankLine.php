<?php

namespace Modules\Accounting\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a bank, wallet or cash statement: money in (amount above
 * zero) or out (below zero). Matched once the journal lines set against it
 * add up to its amount; locked once a reconciliation includes it.
 */
#[Fillable(['organization_id', 'account_id', 'line_date', 'description', 'reference', 'amount_minor', 'fingerprint', 'import_id', 'created_by'])]
class BankLine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'acc_bank_lines';

    protected function casts(): array
    {
        return ['line_date' => 'immutable_date', 'amount_minor' => 'integer', 'matched_minor' => 'integer'];
    }

    public function isMatched(): bool
    {
        return $this->matched_minor === $this->amount_minor;
    }

    public function isLocked(): bool
    {
        return $this->reconciliation_id !== null;
    }
}
