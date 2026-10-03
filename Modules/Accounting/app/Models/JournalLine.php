<?php

namespace Modules\Accounting\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One debit or credit of a journal, to an account and a cost centre (the
 * company itself, or one of its branches or departments). Lines are written
 * once; while a journal is a draft its lines are replaced as a whole.
 */
#[Fillable(['organization_id', 'journal_id', 'line_no', 'account_id', 'cost_centre_id', 'debit_minor', 'credit_minor', 'memo'])]
class JournalLine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'acc_journal_lines';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Journal lines are never changed; a draft gets new lines instead.'));
    }

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'debit_minor' => 'integer', 'credit_minor' => 'integer'];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
