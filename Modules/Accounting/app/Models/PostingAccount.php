<?php

namespace Modules\Accounting\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Which account of the company a module's postings go to, e.g.
 * "payroll.salary_expense" -> 5200 Salaries and wages.
 */
#[Fillable(['organization_id', 'posting_key', 'account_id', 'version'])]
class PostingAccount extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'acc_posting_accounts';

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
