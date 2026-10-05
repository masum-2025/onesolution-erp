<?php

namespace Modules\Inventory\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A receipt, issue, transfer or adjustment:
 *
 *   draft -> post -> posted                       (receipt, issue; adjustments within the limit)
 *   draft -> post -> pending_approval -> approve  (adjustments above the limit: someone else)
 *   draft -> dispatch -> in_transit -> receive    (transfers; what did not arrive is a loss)
 *   draft | pending_approval -> cancel
 */
#[Fillable(['organization_id', 'type', 'number', 'status', 'warehouse_id', 'to_warehouse_id', 'document_date', 'counterparty', 'reference', 'reason', 'currency_code', 'created_by', 'op_id', 'version'])]
class Document extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const TYPES = ['receipt', 'issue', 'transfer', 'adjustment'];

    public const DRAFT = 'draft';

    public const PENDING = 'pending_approval';

    public const IN_TRANSIT = 'in_transit';

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    protected $table = 'inv_documents';

    protected function casts(): array
    {
        return [
            'document_date' => 'immutable_date',
            'value_minor' => 'integer',
            'posted_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
