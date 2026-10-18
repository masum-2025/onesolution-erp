<?php

namespace Modules\StudentAttendance\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Days the institution is closed (a campus only when unit_id is set, some
 * classes only when level_ids is set), beyond the weekly days off.
 */
#[Fillable(['organization_id', 'unit_id', 'starts_on', 'ends_on', 'name', 'level_ids', 'created_by'])]
class Holiday extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $table = 'sta_holidays';

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'level_ids' => 'array'];
    }
}
