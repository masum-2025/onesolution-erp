<?php

namespace Modules\Crm\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An estimate or a quotation for a contact: lines, totals with VAT, valid until a day, then accepted or declined.
 */
#[Fillable(['organization_id', 'unit_id', 'kind', 'number', 'status', 'contact_id', 'deal_id', 'from_quote_id', 'issue_date', 'valid_until', 'subject', 'notes', 'terms', 'currency_code', 'prices_include_tax', 'subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor', 'extra', 'invoice_id', 'decline_reason', 'sent_at', 'decided_at', 'created_by', 'version'])]
class Quote extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const KINDS = ['estimate', 'quotation'];

    public const DRAFT = 'draft';

    public const SENT = 'sent';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    /** An estimate turned into a quotation. */
    public const CONVERTED = 'converted';

    protected $table = 'crm_quotes';

    protected function casts(): array
    {
        return [
            'issue_date' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'prices_include_tax' => 'boolean',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'extra' => 'array',
            'sent_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
