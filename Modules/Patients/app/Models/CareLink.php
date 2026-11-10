<?php

namespace Modules\Patients\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A member of staff caring for a patient (a doctor at a visit, a nurse on a
 * ward), written by the module that knows it. With patients.doctor_sees =
 * own, clinicians see only the patients linked to them here.
 */
#[Fillable(['organization_id', 'patient_id', 'user_id', 'source_module'])]
class CareLink extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'patients_care_links';
}
