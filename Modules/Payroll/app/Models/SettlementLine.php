<?php

namespace Modules\Payroll\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A line of a final settlement: worked out (with how) or added by hand. */
#[Fillable(['organization_id', 'settlement_id', 'line_no', 'kind', 'code', 'amount_minor', 'taxable', 'basis', 'manual', 'loan_id', 'created_by'])]
class SettlementLine extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'pay_settlement_lines';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'taxable' => 'boolean', 'manual' => 'boolean', 'basis' => 'array', 'line_no' => 'integer'];
    }
}
