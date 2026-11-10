<?php

namespace Modules\PatientBilling\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One service on a bill, at the price of the day it was added (a later price
 * change never touches it). Lines are never deleted: on an open bill a
 * wrong line is voided. Lines from other departments carry their source.
 */
#[Fillable([
    'organization_id', 'bill_id', 'service_id', 'description', 'source_module', 'source_type', 'source_id', 'quantity_milli',
    'unit_price_minor', 'gross_minor', 'discount_minor', 'tax_rate_bp', 'tax_minor', 'total_minor', 'voided_at', 'voided_by', 'created_by',
])]
class BillLine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'patient_billing_bill_lines';

    protected function casts(): array
    {
        return [
            'description' => 'array', 'quantity_milli' => 'integer', 'unit_price_minor' => 'integer', 'gross_minor' => 'integer',
            'discount_minor' => 'integer', 'tax_rate_bp' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function descriptionIn(?string $locale): string
    {
        $texts = (array) $this->description;

        return (string) ($texts[$locale ?? app()->getLocale()] ?? $texts['en'] ?? reset($texts) ?: '');
    }
}
