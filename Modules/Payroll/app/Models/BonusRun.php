<?php

namespace Modules\Payroll\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A festival bonus (Eid, Puja, Christmas …) paid apart from the salary:
 *
 *   draft (calculated, lines changed, calculated again) -> submit -> pending_approval
 *     -> approve (another person; posted) -> approved -> paid
 *     -> reject -> draft
 */
#[Fillable(['organization_id', 'bonus_on', 'rate_bp', 'status', 'currency_code', 'created_by', 'version'])]
class BonusRun extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const DRAFT = 'draft';

    public const PENDING = 'pending_approval';

    public const APPROVED = 'approved';

    public const PAID = 'paid';

    protected $table = 'pay_bonus_runs';

    /** @var list<string> */
    public array $translatable = ['title'];

    protected function casts(): array
    {
        return [
            'bonus_on' => 'immutable_date',
            'rate_bp' => 'integer',
            'employees' => 'integer',
            'gross_minor' => 'integer',
            'tax_minor' => 'integer',
            'net_minor' => 'integer',
            'calculated_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'paid_on' => 'immutable_date',
            'version' => 'integer',
        ];
    }
}
