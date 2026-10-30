<?php

namespace Modules\Projects\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** The usual steps of a kind of work (e.g. "BSCI audit"), kept by a group or a company; switched off, never removed. */
#[Fillable(['organization_id', 'service', 'is_active', 'version'])]
class Template extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'prj_templates';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer'];
    }
}
