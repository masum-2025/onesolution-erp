<?php

namespace Modules\Attendance\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A day off for the whole company (unit_id null) or for one unit and the units below it. */
#[Fillable(['organization_id', 'unit_id', 'on'])]
class Holiday extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'att_holidays';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['on' => 'immutable_date'];
    }
}
