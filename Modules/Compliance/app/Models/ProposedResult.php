<?php

namespace Modules\Compliance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An audit result a connected provider sent for a factory: waiting until
 * someone of the client accepts it into the register or rejects it.
 */
#[Fillable(['organization_id', 'audit_id', 'request_id', 'connection_id', 'message_id', 'from_name', 'service_code', 'service_name', 'last_audit_on', 'expires_on', 'result', 'certificate_no', 'audit_type_code', 'note', 'files', 'status', 'decided_by', 'decided_at', 'reject_reason', 'version'])]
class ProposedResult extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    public const REJECTED = 'rejected';

    protected $table = 'cmp_results';

    protected function casts(): array
    {
        return [
            'last_audit_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'files' => 'array',
            'decided_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
