<?php

namespace Modules\Diagnostics\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Test (made by make:erp-module).
 */
#[Fillable(['organization_id', 'unit_id', 'code', 'department', 'sample_type', 'service_code', 'turnaround_hours', 'is_active', 'created_by', 'op_id', 'version'])]
class Test extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'diagnostics_tests';

    protected $attributes = ['version' => 1];

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'turnaround_hours' => 'integer', 'is_active' => 'boolean'];
    }
}
