<?php

namespace Modules\Payroll\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A salary increment round ("Yearly increment 2027", from a day):
 *
 *   draft (lines filled, changed, filled again) -> submit -> pending_approval
 *     -> approve (other people, payroll.salary_approval_levels) -> approved
 *     -> apply -> applied (each new basic set as a salary from effective_from)
 *     -> reject -> draft;  draft or approved -> cancel -> cancelled
 */
#[Fillable(['organization_id', 'cycle_id', 'effective_from', 'status', 'currency_code', 'created_by', 'version'])]
class IncrementRound extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const DRAFT = 'draft';

    public const PENDING = 'pending_approval';

    public const APPROVED = 'approved';

    public const APPLIED = 'applied';

    public const CANCELLED = 'cancelled';

    protected $table = 'pay_increment_rounds';

    /** @var list<string> */
    public array $translatable = ['title'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'employees' => 'integer',
            'current_minor' => 'integer',
            'new_minor' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
