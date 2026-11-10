<?php

namespace Modules\Diagnostics\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One value a test reports (Hb, WBC, a finding): a number with its unit and
 * decimal places, a free text, or one of fixed choices. Never deleted once
 * used; turned off instead.
 */
#[Fillable(['organization_id', 'test_id', 'name', 'unit', 'result_type', 'choices', 'decimals', 'sort', 'is_active'])]
class Parameter extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const TYPES = ['number', 'text', 'choice'];

    protected $table = 'diagnostics_parameters';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['choices' => 'array', 'decimals' => 'integer', 'sort' => 'integer', 'is_active' => 'boolean'];
    }
}
