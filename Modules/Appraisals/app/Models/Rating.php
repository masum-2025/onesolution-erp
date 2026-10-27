<?php

namespace Modules\Appraisals\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A goal's or a competency's rating in a review: the employee's own and the manager's, each with a comment (encrypted). */
#[Fillable(['organization_id', 'review_id', 'goal_id', 'competency_id', 'self_rating', 'self_comment', 'manager_rating', 'manager_comment'])]
class Rating extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'apr_ratings';

    protected $hidden = ['self_comment', 'manager_comment'];

    protected function casts(): array
    {
        return ['self_rating' => 'integer', 'manager_rating' => 'integer', 'self_comment' => 'encrypted', 'manager_comment' => 'encrypted'];
    }
}
