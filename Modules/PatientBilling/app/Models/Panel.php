<?php

namespace Modules\PatientBilling\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Corporate or panel client (made by make:erp-module).
 */
#[Fillable(['organization_id', 'unit_id', 'name', 'kind', 'credit_limit_minor', 'discount_percent', 'is_active', 'currency', 'created_by', 'op_id', 'version'])]
class Panel extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'patient_billing_panels';

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'credit_limit_minor' => 'integer', 'discount_percent' => 'integer', 'is_active' => 'boolean'];
    }
}
