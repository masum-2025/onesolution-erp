<?php

namespace Modules\Compliance\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** An audit service (BSCI, WRAP, ISO 45001...): its code, name per language and how long a certificate usually lasts; switched off, never removed. */
#[Fillable(['organization_id', 'code', 'validity_months', 'is_active', 'sort_order', 'version'])]
class Service extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'cmp_services';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['validity_months' => 'integer', 'is_active' => 'boolean', 'sort_order' => 'integer', 'version' => 'integer'];
    }
}
