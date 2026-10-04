<?php

namespace Modules\Accounting\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\Accounting\Enums\DocumentStatus;
use Modules\Accounting\Enums\DocumentType;

/**
 * A sales invoice or credit note, or a purchase bill or vendor credit. Once
 * posted its date, party, lines and total never change: only how much of it
 * is settled, and whether it was voided.
 */
#[Fillable([
    'organization_id', 'type', 'party_id', 'issue_date', 'due_date', 'reference', 'notes',
    'status', 'currency_code', 'net_minor', 'tax_minor', 'total_minor', 'prices_include_tax', 'created_by', 'version',
])]
class Document extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'acc_documents';

    /** What may still change on a posted document. */
    private const WRITABLE_WHEN_POSTED = ['status', 'allocated_minor', 'voided_at', 'void_reason', 'version', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (Document $document) {
            $original = $document->getOriginal('status');
            if ($original instanceof DocumentStatus && ($original->isPosted() || $original === DocumentStatus::Void)
                && array_diff(array_keys($document->getDirty()), self::WRITABLE_WHEN_POSTED) !== []) {
                throw new LogicException('Posted documents never change; void them instead.');
            }
        });
        static::deleting(function (Document $document) {
            if (! $document->status->isEditable()) {
                throw new LogicException('Only drafts and rejected documents can be removed.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'issue_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'net_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'prices_include_tax' => 'boolean',
            'is_opening' => 'boolean',
            'allocated_minor' => 'integer',
            'posted_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /** What is still owed (or, for a credit, still to use). */
    public function balance(): int
    {
        return $this->total_minor - $this->allocated_minor;
    }
}
