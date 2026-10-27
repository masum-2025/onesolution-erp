<?php

namespace Modules\Recruitment\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A job at a unit: its title and description per language, the HRM
 * position it fills (optional), how many people, a salary range (shown or
 * not), and whether the public careers page lists it. A draft until
 * opened; closed by hand or when its last place is filled.
 */
#[Fillable(['organization_id', 'unit_id', 'position_id', 'employment_type', 'vacancies', 'hired_count', 'currency', 'salary_min_minor', 'salary_max_minor', 'show_salary', 'is_public', 'closes_on', 'status', 'opened_at', 'closed_at', 'created_by', 'version'])]
class Opening extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const DRAFT = 'draft';

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const FILLED = 'filled';

    public const STATUSES = [self::DRAFT, self::OPEN, self::CLOSED, self::FILLED];

    protected $table = 'rec_openings';

    /** @var list<string> */
    public array $translatable = ['title', 'description'];

    protected function casts(): array
    {
        return [
            'vacancies' => 'integer', 'hired_count' => 'integer', 'salary_min_minor' => 'integer', 'salary_max_minor' => 'integer',
            'show_salary' => 'boolean', 'is_public' => 'boolean', 'closes_on' => 'date', 'opened_at' => 'datetime', 'closed_at' => 'datetime', 'version' => 'integer',
        ];
    }
}
