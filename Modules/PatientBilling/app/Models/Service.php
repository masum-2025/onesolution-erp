<?php

namespace Modules\PatientBilling\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Service (made by make:erp-module).
 */
#[Fillable(['organization_id', 'unit_id', 'code', 'category', 'price_minor', 'tax_code_id', 'is_active', 'currency', 'created_by', 'op_id', 'version'])]
class Service extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'patient_billing_services';

    protected $attributes = ['version' => 1];

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'price_minor' => 'integer', 'is_active' => 'boolean'];
    }
}
