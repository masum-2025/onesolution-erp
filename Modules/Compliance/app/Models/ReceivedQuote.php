<?php

namespace Modules\Compliance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A quotation a connected provider sent: pending until the client accepts
 * or declines it; replaced when the provider sends a newer version.
 */
#[Fillable(['organization_id', 'connection_id', 'message_id', 'provider_quote_id', 'request_id', 'from_name', 'number', 'subject', 'issue_date', 'valid_until', 'currency_code', 'prices_include_tax', 'subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor', 'lines', 'notes', 'terms', 'status', 'reason', 'decided_by', 'decided_at', 'version'])]
class ReceivedQuote extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const REPLACED = 'replaced';

    protected $table = 'cmp_quotes';

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
            'lines' => 'array',
            'decided_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
