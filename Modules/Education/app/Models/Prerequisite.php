<?php

namespace Modules\Education\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A subject that must be passed before another (checked at course registration).
 */
#[Fillable(['organization_id', 'subject_id', 'requires_subject_id'])]
class Prerequisite extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'edu_subject_prerequisites';
}
