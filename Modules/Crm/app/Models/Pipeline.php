<?php

namespace Modules\Crm\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's way of selling: its stages, in order.
 */
#[Fillable(['organization_id', 'key', 'is_default', 'is_active', 'version'])]
class Pipeline extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'crm_pipelines';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }
}
