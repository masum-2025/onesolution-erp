<?php

namespace Modules\Education\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * What students follow ("Secondary", "BSc CSE") and how it moves on: by year, semester or term.
 */
#[Fillable(['organization_id', 'academic_unit_id', 'name', 'code', 'progression', 'periods_per_year', 'total_credits_centi', 'level_label', 'section_label', 'is_active', 'sort_order', 'version'])]
class Program extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const PROGRESSIONS = ['year', 'semester', 'term'];

    protected $table = 'edu_programs';

    /** @var list<string> */
    public array $translatable = ['name', 'level_label', 'section_label'];

    protected function casts(): array
    {
        return [
            'periods_per_year' => 'integer',
            'total_credits_centi' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'version' => 'integer',
        ];
    }
}
