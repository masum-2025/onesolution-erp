<?php

namespace Modules\Appraisals\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A review cycle: its name per language, the period reviewed, the unit
 * (and below) whose people take part, the last day of each step. The
 * rules that score it (goal share, self review, the scale) are kept as
 * they were when it opened, so its results never change with the rules.
 */
#[Fillable(['organization_id', 'unit_id', 'period_from', 'period_to', 'goals_due', 'self_due', 'manager_due', 'status', 'goal_share_percent', 'self_review', 'scale', 'opened_at', 'closed_at', 'created_by', 'version'])]
class Cycle extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const DRAFT = 'draft';

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $table = 'apr_cycles';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'period_from' => 'date', 'period_to' => 'date', 'goals_due' => 'date', 'self_due' => 'date', 'manager_due' => 'date',
            'goal_share_percent' => 'integer', 'self_review' => 'boolean', 'scale' => 'array', 'opened_at' => 'datetime', 'closed_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
