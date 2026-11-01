<?php

namespace Modules\Projects\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A payment a connected client says it made against a project's invoice;
 * decided once: recorded as a receipt in Accounting, or not received.
 */
#[Fillable(['organization_id', 'project_id', 'connection_id', 'client_advice_id', 'client_unit_id', 'message_id', 'from_name', 'by_name', 'invoice_id', 'invoice_number', 'amount_minor', 'currency_code', 'paid_on', 'method', 'reference', 'note', 'status', 'settlement_id', 'reason', 'decided_by', 'decided_at', 'version'])]
class PaymentAdvice extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const NEW = 'new';

    public const RECORDED = 'recorded';

    public const NOT_RECEIVED = 'not_received';

    public const METHODS = ['bank', 'cash', 'cheque', 'mobile', 'other'];

    protected $table = 'prj_payment_advices';

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'paid_on' => 'immutable_date',
            'decided_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
