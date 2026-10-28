<?php

namespace Modules\Compliance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A reminder stage sent for one expiry day of a register line (stage -1: expired). */
#[Fillable(['organization_id', 'audit_id', 'expires_on', 'stage', 'sent_on', 'created_at'])]
class ReminderSent extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    public const EXPIRED = -1;

    protected $table = 'cmp_reminders_sent';

    protected function casts(): array
    {
        return ['expires_on' => 'immutable_date', 'sent_on' => 'immutable_date', 'stage' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
