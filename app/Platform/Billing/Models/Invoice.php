<?php

namespace App\Platform\Billing\Models;

use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An invoice or a credit note, to a partner (wholesale) or to a client
 * (direct, revenue share). Issued documents never change: amounts, lines,
 * seller and buyer are frozen; only status, paid_at and the payment reference
 * move. Corrections are credit notes. Nothing is mass assignable.
 *
 * Not tenant-scoped on purpose (partner and platform documents); every read
 * filters by partner or organization from the context.
 */
#[Table('invoices')]
class Invoice extends Model
{
    use HasUlids;

    public const INVOICE = 'invoice';

    public const CREDIT_NOTE = 'credit_note';

    public const TO_PARTNER = 'partner';

    public const TO_ORGANIZATION = 'organization';

    // issued → paid; issued → credited (fully credited before payment).
    public const ISSUED = 'issued';

    public const PAID = 'paid';

    public const CREDITED = 'credited';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'issued_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'subtotal_minor' => 'integer',
            'tax_rate_bp' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'seller' => 'array',
            'buyer' => 'array',
        ];
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(Invoice::class, 'credits_invoice_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function credits(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'credits_invoice_id');
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function brandPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'brand_partner_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isCreditNote(): bool
    {
        return $this->type === self::CREDIT_NOTE;
    }

    public function isOverdue(): bool
    {
        return $this->status === self::ISSUED && $this->type === self::INVOICE && $this->due_at !== null && $this->due_at->isPast();
    }
}
