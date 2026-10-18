<?php

namespace Modules\StudentAttendance\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A period of a campus's day (1st, 2nd…) with its local times. One no
 * longer used is switched off, never deleted once a sheet names it.
 */
#[Fillable(['organization_id', 'unit_id', 'number', 'name', 'starts_at', 'ends_at', 'is_active', 'version'])]
class Period extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $table = 'sta_periods';

    protected $attributes = ['is_active' => true, 'version' => 1];

    protected function casts(): array
    {
        return ['number' => 'integer', 'is_active' => 'boolean', 'version' => 'integer'];
    }
}
