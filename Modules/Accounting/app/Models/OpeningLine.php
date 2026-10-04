<?php

namespace Modules\Accounting\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One opening balance: an account's balance (debit or credit), or an old
 * invoice a customer still owes or a bill still owed to a vendor (with its
 * reference and dates, so aging is right). A draft's lines are replaced as a
 * whole; lines are never changed.
 */
#[Fillable([
    'organization_id', 'opening_id', 'line_no', 'kind', 'account_id', 'party_id', 'cost_centre_id',
    'debit_minor', 'credit_minor', 'reference', 'issue_date', 'due_date',
])]
class OpeningLine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const KINDS = ['account', 'customer', 'vendor'];

    protected $table = 'acc_opening_lines';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Opening lines are never changed; a draft gets new lines instead.'));
    }

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'debit_minor' => 'integer',
            'credit_minor' => 'integer',
            'issue_date' => 'immutable_date',
            'due_date' => 'immutable_date',
        ];
    }

    /** What the line is worth, on whichever side it sits. */
    public function amount(): int
    {
        return $this->debit_minor + $this->credit_minor;
    }
}
