<?php

namespace Modules\Payroll\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Something a salary is made of: an earning (house rent, medical,
 * conveyance) or a deduction (provident fund, canteen), named in every
 * language, taxable or not, prorated by days worked or not. Switched off,
 * never removed, once used.
 */
#[Fillable(['organization_id', 'code', 'kind', 'taxable', 'prorated', 'sort', 'is_active', 'version'])]
class Component extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const KINDS = ['earning', 'deduction'];

    protected $table = 'pay_components';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['taxable' => 'boolean', 'prorated' => 'boolean', 'sort' => 'integer', 'is_active' => 'boolean', 'version' => 'integer'];
    }
}
