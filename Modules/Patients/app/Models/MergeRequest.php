<?php

namespace Modules\Patients\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A request to fold one patient record (source) into another (target): made
 * by one person, approved or rejected by another (maker-checker).
 */
#[Fillable(['organization_id', 'source_id', 'target_id', 'reason', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note'])]
class MergeRequest extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $table = 'patients_merges';

    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime'];
    }
}
