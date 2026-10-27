<?php

namespace Modules\Appraisals\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One employee's review in a cycle: the step it is at (goals, self,
 * manager, acknowledge, done), the manager who reviews them (as at the
 * cycle's opening), the summaries (encrypted) and the result: scores per
 * mille and the band.
 */
#[Fillable(['organization_id', 'cycle_id', 'unit_id', 'employee_id', 'manager_employee_id', 'manager_user_id', 'stage', 'goals_submitted_at', 'self_summary', 'manager_summary', 'employee_comment', 'goals_score', 'competencies_score', 'score', 'band', 'completed_at', 'version'])]
class Review extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const GOALS = 'goals';

    public const SELF = 'self';

    public const MANAGER = 'manager';

    public const ACKNOWLEDGE = 'acknowledge';

    public const DONE = 'done';

    /** In order. */
    public const STAGES = [self::GOALS, self::SELF, self::MANAGER, self::ACKNOWLEDGE, self::DONE];

    protected $table = 'apr_reviews';

    protected $hidden = ['self_summary', 'manager_summary', 'employee_comment'];

    protected function casts(): array
    {
        return [
            'goals_submitted_at' => 'datetime', 'self_summary' => 'encrypted', 'manager_summary' => 'encrypted', 'employee_comment' => 'encrypted',
            'goals_score' => 'integer', 'competencies_score' => 'integer', 'score' => 'integer', 'completed_at' => 'datetime', 'version' => 'integer',
        ];
    }
}
