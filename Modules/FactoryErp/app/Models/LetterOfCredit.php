<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A buyer's letter of credit: amount and tolerance, last day to ship, expiry, terms, the documents it asks for; the orders it pays for in LcOrder.
 */
#[Fillable(['organization_id', 'unit_id', 'buyer_id', 'number', 'issued_on', 'issuing_bank', 'advising_bank', 'currency', 'amount_minor', 'tolerance_bp', 'latest_ship_on', 'expires_on',
    'partial_allowed', 'transshipment_allowed', 'port_loading', 'port_discharge', 'payment_days', 'documents', 'status', 'note', 'created_by', 'version'])]
class LetterOfCredit extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    /** Documents an L/C may ask for (labels in factory_erp::factory.lc_documents and the screens). */
    public const DOCUMENTS = ['commercial_invoice', 'packing_list', 'bill_of_lading', 'airway_bill', 'certificate_of_origin', 'beneficiary_certificate', 'bill_of_exchange', 'inspection_certificate', 'insurance'];

    protected $table = 'fac_lcs';

    protected $attributes = ['status' => 'open', 'version' => 1];

    protected function casts(): array
    {
        return [
            'issued_on' => 'immutable_date', 'latest_ship_on' => 'immutable_date', 'expires_on' => 'immutable_date', 'amount_minor' => 'integer', 'tolerance_bp' => 'integer',
            'partial_allowed' => 'boolean', 'transshipment_allowed' => 'boolean', 'payment_days' => 'integer', 'documents' => 'array', 'version' => 'integer',
        ];
    }

    /** The most it pays: amount plus the tolerance (rounded down). */
    public function ceiling(): int
    {
        return intdiv($this->amount_minor * (10000 + $this->tolerance_bp), 10000);
    }
}
