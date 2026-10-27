<?php

namespace Modules\Appraisals\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** One step in the history of a review: from and to which stage, by whom, with a note (append-only). */
#[Fillable(['organization_id', 'review_id', 'from_stage', 'to_stage', 'note', 'user_id'])]
class Step extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    protected $table = 'apr_steps';
}
