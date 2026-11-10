<?php

namespace Modules\Diagnostics\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A tube or container taken from a patient, known by its barcode. A rejected
 * sample (clotted, too little) stays on record; a new one is taken.
 */
#[Fillable(['organization_id', 'barcode', 'order_id', 'patient_id', 'sample_type', 'collected_by', 'collected_at', 'rejected_reason', 'rejected_by', 'rejected_at'])]
class Sample extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'diagnostics_samples';

    protected function casts(): array
    {
        return ['collected_at' => 'immutable_datetime', 'rejected_at' => 'immutable_datetime'];
    }
}
