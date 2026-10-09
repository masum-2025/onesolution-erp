<?php

namespace Modules\Education\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An entry of one of the institution's own lists: shift, medium, stream, guardian relation, student category, gender.
 */
#[Fillable(['organization_id', 'kind', 'key', 'name', 'is_active', 'sort_order', 'version'])]
class ListItem extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const KINDS = ['shift', 'medium', 'stream', 'relation', 'category', 'gender'];

    protected $table = 'edu_lists';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'version' => 'integer',
        ];
    }
}
