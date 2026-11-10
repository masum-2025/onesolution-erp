<?php

namespace Modules\PatientBilling\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Money in or out, append-only: a payment on a bill (cash, card, mobile, or
 * from the patient's advance), an advance (no bill yet), or a refund (money
 * back, a separate record). Never changed or removed; only the journal id is
 * filled in once the books are written.
 */
#[Fillable([
    'organization_id', 'unit_id', 'number', 'bill_id', 'patient_id', 'kind', 'method', 'amount_minor', 'currency', 'reference', 'reason',
    'cash_day_id', 'journal_id', 'received_by', 'received_at', 'op_id',
])]
class Payment extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const PAYMENT = 'payment';

    public const ADVANCE = 'advance';

    public const REFUND = 'refund';

    /** Ways money moves; "advance" takes from (or gives back to) the patient's advance. */
    public const METHODS = ['cash', 'card', 'mobile', 'advance'];

    protected $table = 'patient_billing_payments';

    protected static function booted(): void
    {
        static::updating(function (Payment $payment) {
            if (array_keys($payment->getDirty()) !== ['journal_id']) {
                throw new LogicException('Patient payments are append-only.');
            }
        });
        static::deleting(fn () => throw new LogicException('Patient payments are append-only.'));
    }

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'received_at' => 'immutable_datetime'];
    }
}
