<?php

namespace Modules\Hrm\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A job title of a company or one of its units (Teacher, Nurse, Machine
 * operator), named in every language the client uses.
 */
#[Fillable(['organization_id', 'code', 'grade', 'is_active', 'version'])]
class Position extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'hrm_positions';

    /** @var list<string> */
    public array $translatable = ['title'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer'];
    }
}
