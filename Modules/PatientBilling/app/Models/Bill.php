<?php

namespace Modules\PatientBilling\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A patient's bill at a branch: open while services are added, final once
 * totalled (never changed again; a mistake is refunded), or void (an open
 * bill nobody paid). Totals are kept on the bill; paid and refunded add up
 * the money records. On a panel bill, what is still due at finalizing
 * belongs to the panel (panel_due_minor).
 */
#[Fillable([
    'organization_id', 'unit_id', 'number', 'patient_id', 'panel_id', 'status', 'subtotal_minor', 'panel_discount_minor', 'discount_minor',
    'discount_reason', 'tax_minor', 'total_minor', 'paid_minor', 'refunded_minor', 'panel_due_minor', 'currency', 'prices_include_tax',
    'finalized_at', 'finalized_by', 'void_reason', 'journal_id', 'created_by', 'op_id', 'version',
])]
class Bill extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const OPEN = 'open';

    public const FINAL = 'final';

    public const VOID = 'void';

    protected $table = 'patient_billing_bills';

    protected $attributes = ['version' => 1, 'status' => self::OPEN];

    protected function casts(): array
    {
        return [
            'version' => 'integer', 'subtotal_minor' => 'integer', 'panel_discount_minor' => 'integer', 'discount_minor' => 'integer',
            'tax_minor' => 'integer', 'total_minor' => 'integer', 'paid_minor' => 'integer', 'refunded_minor' => 'integer',
            'panel_due_minor' => 'integer', 'prices_include_tax' => 'boolean', 'finalized_at' => 'immutable_datetime',
        ];
    }

    /** What is still to be paid (by the patient, or by the panel once final). */
    public function dueMinor(): int
    {
        return max(0, $this->total_minor - $this->paid_minor);
    }

    /** Money paid on the bill that was not given back. */
    public function keptMinor(): int
    {
        return $this->paid_minor - $this->refunded_minor;
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}
