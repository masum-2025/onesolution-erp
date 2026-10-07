<?php

namespace Modules\Crm\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One step of a pipeline: its chance of winning, and whether it means won or lost.
 */
#[Fillable(['organization_id', 'pipeline_id', 'key', 'sort_order', 'probability_bp', 'outcome', 'is_active', 'version'])]
class Stage extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const OUTCOMES = ['open', 'won', 'lost'];

    protected $table = 'crm_stages';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'probability_bp' => 'integer',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }
}
