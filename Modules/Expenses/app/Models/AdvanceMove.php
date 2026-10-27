<?php

namespace Modules\Expenses\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** One movement of an advance (append-only): paid out, settled by a claim, handed back, or recovered from the salary. */
#[Fillable(['organization_id', 'advance_id', 'kind', 'amount_minor', 'claim_id', 'method', 'run_id', 'journal_id', 'user_id'])]
class AdvanceMove extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const PAID = 'paid';

    public const CLAIM = 'claim';

    public const RETURNED = 'returned';

    public const SALARY = 'salary';

    /** Kinds that bring what is owed down. */
    public const SETTLING = [self::CLAIM, self::RETURNED, self::SALARY];

    public const UPDATED_AT = null;

    protected $table = 'exp_advance_moves';

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
