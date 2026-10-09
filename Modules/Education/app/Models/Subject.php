<?php

namespace Modules\Education\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A subject or course, with credits in hundredths (1.5 -> 150).
 */
#[Fillable(['organization_id', 'code', 'name', 'credits_centi', 'kind', 'is_active', 'version'])]
class Subject extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const KINDS = ['theory', 'lab', 'practical', 'other'];

    protected $table = 'edu_subjects';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'credits_centi' => 'integer',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }
}
