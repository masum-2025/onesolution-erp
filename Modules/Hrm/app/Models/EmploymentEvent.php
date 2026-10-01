<?php

namespace Modules\Hrm\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\Hrm\Enums\EmploymentEventType;

/**
 * One step of an employment (hired, confirmed, transferred, promoted, notice,
 * exited, rehired) with its effective date: the history payroll and audits
 * read. Append-only: written once, never changed or removed.
 */
#[Fillable(['organization_id', 'employee_id', 'type', 'effective_on', 'from', 'to', 'reason', 'actor_user_id'])]
class EmploymentEvent extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'hrm_employment_events';

    const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Employment events are never changed.'));
        static::deleting(fn () => throw new LogicException('Employment events are never removed.'));
    }

    protected function casts(): array
    {
        return [
            'type' => EmploymentEventType::class,
            'effective_on' => 'immutable_date',
            'from' => 'array',
            'to' => 'array',
        ];
    }
}
