<?php

namespace Modules\Education\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A period taught in a year: the whole year, a semester or a term.
 */
#[Fillable(['organization_id', 'academic_year_id', 'kind', 'sequence', 'name', 'starts_on', 'ends_on', 'status', 'version'])]
class Session extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['planned', 'open', 'closed'];

    protected $table = 'edu_sessions';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'version' => 'integer',
        ];
    }
}
