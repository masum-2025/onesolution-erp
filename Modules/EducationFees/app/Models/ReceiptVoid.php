<?php

namespace Modules\EducationFees\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Asking to void a receipt, and another person's decision.
 */
#[Fillable(['organization_id', 'unit_id', 'receipt_id', 'reason', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note', 'version'])]
class ReceiptVoid extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fee_voids';

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
