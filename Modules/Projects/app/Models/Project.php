<?php

namespace Modules\Projects\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A project for a client, run by a company or a branch: planned, active,
 * on hold, done or cancelled (never deleted). Its tasks can change only
 * while it is planned, active or on hold.
 */
#[Fillable(['organization_id', 'company_id', 'code', 'name', 'client_contact_id', 'client_name', 'template_id', 'service', 'manager_id', 'status', 'status_reason', 'starts_on', 'due_on', 'finished_on', 'description', 'created_by', 'version'])]
class Project extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const PLANNED = 'planned';

    public const ACTIVE = 'active';

    public const ON_HOLD = 'on_hold';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PLANNED, self::ACTIVE, self::ON_HOLD, self::DONE, self::CANCELLED];

    /** Where its tasks may still change. */
    public const OPEN = [self::PLANNED, self::ACTIVE, self::ON_HOLD];

    protected $table = 'prj_projects';

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'finished_on' => 'immutable_date',
            'version' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
