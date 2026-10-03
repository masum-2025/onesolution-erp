<?php

namespace Modules\Accounting\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Modules\Accounting\Enums\JournalStatus;

/**
 * A journal entry: balanced debit and credit lines on one date. Drafts and
 * rejected entries may change; a posted entry never does (only the link to
 * the entry that reverses it is written later) and is never removed.
 */
#[Fillable([
    'organization_id', 'entry_date', 'narration', 'status', 'currency_code', 'total_minor',
    'source_module', 'source_type', 'source_id', 'op_id', 'reverses_id', 'created_by', 'version',
])]
class Journal extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'acc_journals';

    /** What may still be written on a posted journal. */
    private const WRITABLE_WHEN_POSTED = ['reversed_by_id', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (Journal $journal) {
            if ($journal->getOriginal('status') === JournalStatus::Posted
                && array_diff(array_keys($journal->getDirty()), self::WRITABLE_WHEN_POSTED) !== []) {
                throw new LogicException('Posted journals are never changed; reverse them instead.');
            }
        });
        static::deleting(function (Journal $journal) {
            if (! $journal->status->isEditable()) {
                throw new LogicException('Only drafts and rejected journals can be removed.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'entry_date' => 'immutable_date',
            'status' => JournalStatus::class,
            'total_minor' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }
}
