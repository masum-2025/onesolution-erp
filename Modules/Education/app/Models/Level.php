<?php

namespace Modules\Education\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A step of a program (Class 6, Semester 3); next_level_id is where promotion goes, none on the last one.
 */
#[Fillable(['organization_id', 'program_id', 'sequence', 'name', 'code', 'next_level_id', 'min_age', 'is_active', 'version'])]
class Level extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'edu_levels';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'min_age' => 'integer',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }
}
