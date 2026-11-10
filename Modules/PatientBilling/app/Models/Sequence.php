<?php

namespace Modules\PatientBilling\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The last bill, receipt or refund number given in a company.
 */
#[Fillable(['organization_id', 'kind', 'last_number'])]
class Sequence extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'patient_billing_sequences';

    protected function casts(): array
    {
        return ['last_number' => 'integer'];
    }
}
