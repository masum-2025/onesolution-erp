<?php

namespace Modules\Payroll\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A salary's make-up (e.g. "Staff grade 3"): its items, beside the basic each employee has. */
#[Fillable(['organization_id', 'code', 'is_active', 'version'])]
class Structure extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'pay_structures';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer'];
    }
}
