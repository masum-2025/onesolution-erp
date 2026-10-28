<?php

namespace Modules\Compliance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** One change of a register line (added, dates, details, imported), with its reason. Append-only: never updated or deleted. */
#[Fillable(['organization_id', 'audit_id', 'kind', 'old', 'new', 'reason', 'user_id', 'created_at'])]
class AuditChange extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    protected $table = 'cmp_audit_changes';

    protected function casts(): array
    {
        return ['old' => 'array', 'new' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
