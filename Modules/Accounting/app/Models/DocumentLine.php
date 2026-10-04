<?php

namespace Modules\Accounting\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One line of a document: what was sold or bought, quantity in thousandths
 * (1.5 = 1500), unit price and amount in minor units, and the income or
 * expense account and cost centre it goes to. A draft's lines are replaced
 * as a whole; lines are never changed.
 */
#[Fillable(['organization_id', 'document_id', 'line_no', 'description', 'quantity_milli', 'unit_price_minor', 'amount_minor', 'account_id', 'cost_centre_id'])]
class DocumentLine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'acc_document_lines';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Document lines are never changed; a draft gets new lines instead.'));
    }

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'quantity_milli' => 'integer', 'unit_price_minor' => 'integer', 'amount_minor' => 'integer'];
    }
}
