<?php

namespace Modules\Expenses\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A kind of expense of the company (travel, meals, ...): its name per language and the posting key it goes to; a mileage kind is paid by kilometre. */
#[Fillable(['organization_id', 'code', 'posting_key', 'mileage', 'per_diem', 'is_active', 'sort_order', 'version'])]
class Category extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'exp_categories';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['mileage' => 'boolean', 'per_diem' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer', 'version' => 'integer'];
    }
}
