<?php

namespace Modules\PatientBilling\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One cashier's day at a branch: opened with the float, closed with the cash
 * counted. A difference within the company's limit closes it; a bigger one
 * waits for a supervisor (review).
 */
#[Fillable([
    'organization_id', 'unit_id', 'user_id', 'currency', 'opening_float_minor', 'opened_at', 'closed_at', 'expected_minor', 'counted_minor',
    'variance_minor', 'status', 'reviewed_by', 'review_note', 'journal_id', 'version',
])]
class CashDay extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const OPEN = 'open';

    public const REVIEW = 'review';

    public const CLOSED = 'closed';

    protected $table = 'patient_billing_cash_days';

    protected $attributes = ['status' => self::OPEN, 'version' => 1];

    protected function casts(): array
    {
        return [
            'opening_float_minor' => 'integer', 'expected_minor' => 'integer', 'counted_minor' => 'integer', 'variance_minor' => 'integer',
            'opened_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'version' => 'integer',
        ];
    }
}
