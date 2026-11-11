<?php

namespace Modules\Outpatient\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Frequent medicines, tests and advice under a name, put into a consultation
 * in one tap. A doctor's own (doctor_id) or for every doctor (null, managers).
 */
#[Fillable(['organization_id', 'doctor_id', 'name', 'body', 'created_by'])]
class Template extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'outpatient_templates';

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['body' => 'array', 'version' => 'integer'];
    }
}
