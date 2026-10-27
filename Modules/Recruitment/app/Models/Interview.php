<?php

namespace Modules\Recruitment\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** An interview of an application: when (UTC), how long, in person, by phone or video, where (a place or a link), and who interviews. */
#[Fillable(['organization_id', 'application_id', 'starts_at', 'minutes', 'mode', 'place', 'interviewer_ids', 'status', 'created_by', 'version'])]
class Interview extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const SCHEDULED = 'scheduled';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    public const MODES = ['in_person', 'phone', 'video'];

    protected $table = 'rec_interviews';

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'minutes' => 'integer', 'interviewer_ids' => 'array', 'version' => 'integer'];
    }
}
