<?php

namespace Modules\Appraisals\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A goal of a review: what is to be achieved, and how much it weighs (percent). */
#[Fillable(['organization_id', 'review_id', 'title', 'description', 'weight', 'sort_order'])]
class Goal extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'apr_goals';

    protected function casts(): array
    {
        return ['weight' => 'integer', 'sort_order' => 'integer'];
    }
}
