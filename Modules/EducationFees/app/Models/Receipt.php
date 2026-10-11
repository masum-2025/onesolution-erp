<?php

namespace Modules\EducationFees\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Money taken from a student's family, numbered; voided, never changed.
 */
#[Fillable(['organization_id', 'unit_id', 'student_id', 'number', 'received_on', 'method', 'reference', 'amount_minor', 'currency', 'status', 'note', 'collected_by', 'payment_id', 'journal_id', 'op_id', 'version'])]
class Receipt extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const METHODS = ['cash', 'bank', 'mobile', 'online'];

    protected $table = 'fee_receipts';

    protected function casts(): array
    {
        return ['received_on' => 'immutable_date', 'amount_minor' => 'integer', 'version' => 'integer'];
    }
}
