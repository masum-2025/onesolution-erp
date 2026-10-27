<?php

namespace Modules\Recruitment\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** One step in the history of an application: from and to which stage, and how it then stood (append-only). */
#[Fillable(['organization_id', 'application_id', 'from_stage', 'to_stage', 'status', 'note', 'user_id'])]
class StageMove extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    protected $table = 'rec_stage_moves';
}
