<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of defect the company's QC counts (name in each language); switched off, never removed.
 */
#[Fillable(['organization_id', 'code', 'sort_order', 'is_active', 'version'])]
class DefectType extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $attributes = ['is_active' => true, 'version' => 1];

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $table = 'fac_defect_types';

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean', 'version' => 'integer'];
    }
}
