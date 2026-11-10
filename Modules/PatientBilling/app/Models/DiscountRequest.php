<?php

namespace Modules\PatientBilling\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A discount above the company's limit, waiting for someone other than the
 * person who asked (maker-checker).
 */
#[Fillable(['organization_id', 'bill_id', 'amount_minor', 'percent_bp', 'reason', 'status', 'requested_by', 'decided_by', 'decided_at', 'note'])]
class DiscountRequest extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $table = 'patient_billing_discount_requests';

    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'percent_bp' => 'integer', 'decided_at' => 'immutable_datetime'];
    }
}
