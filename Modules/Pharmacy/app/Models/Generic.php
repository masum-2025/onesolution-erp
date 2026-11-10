<?php

namespace Modules\Pharmacy\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Generic (made by make:erp-module).
 */
#[Fillable(['organization_id', 'unit_id', 'drug_class', 'is_controlled', 'controlled_schedule', 'in_use', 'created_by', 'op_id', 'version'])]
class Generic extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'pharmacy_generics';

    protected $attributes = ['version' => 1];

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'is_controlled' => 'boolean', 'in_use' => 'boolean'];
    }
}
