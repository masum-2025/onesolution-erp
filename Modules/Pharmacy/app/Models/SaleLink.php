<?php

namespace Modules\Pharmacy\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A POS sale made at the pharmacy counter, with its prescription.
 */
#[Fillable(['organization_id', 'unit_id', 'pos_sale_id', 'sale_number', 'total_minor', 'currency', 'sold_at', 'patient_name', 'prescription_ref', 'prescriber_name', 'prescriber_bmdc', 'has_controlled', 'created_by', 'op_id'])]
class SaleLink extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'pharmacy_sale_links';

    protected function casts(): array
    {
        return ['total_minor' => 'integer', 'sold_at' => 'immutable_datetime', 'has_controlled' => 'boolean'];
    }
}
