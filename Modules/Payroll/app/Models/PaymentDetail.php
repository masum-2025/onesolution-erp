<?php

namespace Modules\Payroll\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Where an employee's pay goes: a bank or mobile account (number encrypted
 * at rest, shown masked), or cash.
 */
#[Fillable(['organization_id', 'employee_id', 'method', 'provider', 'account_name', 'account_number', 'branch', 'version'])]
class PaymentDetail extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const METHODS = ['bank', 'mobile', 'cash'];

    protected $table = 'pay_payment_details';

    protected function casts(): array
    {
        return ['account_number' => 'encrypted', 'version' => 'integer'];
    }

    /** "••••4521": the last four characters only. */
    public function maskedNumber(): ?string
    {
        $number = (string) $this->account_number;

        return $number === '' ? null : '••••'.mb_substr($number, -4);
    }
}
