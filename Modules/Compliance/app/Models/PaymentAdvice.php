<?php

namespace Modules\Compliance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A payment the client told the provider about, against a received invoice;
 * written once, the provider's answer added when it comes.
 */
#[Fillable(['organization_id', 'invoice_id', 'connection_id', 'amount_minor', 'currency_code', 'paid_on', 'method', 'reference', 'note', 'status', 'reply_reason', 'replied_at', 'op_id', 'sent_by', 'version'])]
class PaymentAdvice extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const SENT = 'sent';

    public const RECORDED = 'recorded';

    public const NOT_RECEIVED = 'not_received';

    public const METHODS = ['bank', 'cash', 'cheque', 'mobile', 'other'];

    protected $table = 'cmp_payment_advices';

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'paid_on' => 'immutable_date',
            'replied_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
