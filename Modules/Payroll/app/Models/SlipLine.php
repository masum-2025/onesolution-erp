<?php

namespace Modules\Payroll\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** One line of a slip (earning, deduction or tax), with the name it had then. */
#[Fillable(['organization_id', 'slip_id', 'line_no', 'kind', 'code', 'amount_minor', 'taxable'])]
class SlipLine extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'pay_slip_lines';

    public $timestamps = false;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'amount_minor' => 'integer', 'taxable' => 'boolean'];
    }
}
