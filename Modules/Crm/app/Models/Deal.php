<?php

namespace Modules\Crm\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An opportunity with a contact, moving through the stages of a pipeline until won or lost.
 */
#[Fillable(['organization_id', 'unit_id', 'contact_id', 'pipeline_id', 'stage_id', 'title', 'value_minor', 'currency_code', 'expected_on', 'owner_id', 'status', 'lost_reason', 'closed_at', 'extra', 'created_by', 'version'])]
class Deal extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const OPEN = 'open';

    public const WON = 'won';

    public const LOST = 'lost';

    protected $table = 'crm_deals';

    protected function casts(): array
    {
        return [
            'value_minor' => 'integer',
            'expected_on' => 'immutable_date',
            'closed_at' => 'immutable_datetime',
            'extra' => 'array',
            'version' => 'integer',
        ];
    }
}
