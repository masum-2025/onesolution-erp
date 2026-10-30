<?php

namespace Modules\Projects\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** One step of a template: its title per language and how many days after the start it is due. */
#[Fillable(['organization_id', 'template_id', 'sort_order', 'due_after_days'])]
class TemplateTask extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'prj_template_tasks';

    /** @var list<string> */
    public array $translatable = ['title'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'due_after_days' => 'integer'];
    }
}
