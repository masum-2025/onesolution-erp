<?php

namespace Modules\Inpatient\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Ward or cabin (made by make:erp-module).
 */
#[Fillable(['organization_id', 'unit_id', 'kind', 'is_active', 'created_by', 'op_id', 'version'])]
class Ward extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'inpatient_wards';

    protected $attributes = ['version' => 1];

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'is_active' => 'boolean'];
    }
}
