<?php

namespace Modules\Education\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A subject at a level of a curriculum: compulsory, elective or optional, for one stream or all.
 */
#[Fillable(['organization_id', 'curriculum_id', 'level_id', 'subject_id', 'kind', 'stream_id', 'sort_order'])]
class CurriculumItem extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const KINDS = ['compulsory', 'elective', 'optional'];

    protected $table = 'edu_curriculum_items';

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
