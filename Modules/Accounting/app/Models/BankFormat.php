<?php

namespace Modules\Accounting\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Which columns of an account's statement file hold what (date,
 * description, reference, one signed amount or money in and out), and how
 * its dates are written. Remembered from the last import.
 */
#[Fillable(['organization_id', 'account_id', 'columns', 'date_format'])]
class BankFormat extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    /** Date formats offered (PHP format => example). */
    public const DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'm/d/Y', 'd-M-Y', 'd M Y', 'd/m/y'];

    protected $table = 'acc_bank_formats';

    protected function casts(): array
    {
        return ['columns' => 'array'];
    }
}
