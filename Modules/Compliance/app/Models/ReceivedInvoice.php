<?php

namespace Modules\Compliance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An invoice a connected provider sent, as it last came (its status and
 * what is still owed follow the provider's books); taken into the client's
 * books once as a draft bill.
 */
#[Fillable(['organization_id', 'connection_id', 'provider_invoice_id', 'message_id', 'as_of', 'request_id', 'from_name', 'number', 'issue_date', 'due_date', 'currency_code', 'prices_include_tax', 'net_minor', 'tax_minor', 'total_minor', 'balance_minor', 'lines', 'notes', 'provider_status', 'bill_id', 'billed_by', 'billed_at', 'version'])]
class ReceivedInvoice extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['posted', 'partly_paid', 'paid', 'void'];

    protected $table = 'cmp_invoices';

    protected function casts(): array
    {
        return [
            'issue_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'prices_include_tax' => 'boolean',
            'net_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'balance_minor' => 'integer',
            'as_of' => 'integer',
            'lines' => 'array',
            'billed_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
