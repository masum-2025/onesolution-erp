<?php

namespace Modules\Outpatient\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Doctor (made by make:erp-module).
 */
#[Fillable(['organization_id', 'unit_id', 'registration_no', 'degrees', 'specialty', 'mobile', 'fee_service_code', 'follow_up_service_code', 'is_active', 'created_by', 'op_id', 'version'])]
class Doctor extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'outpatient_doctors';

    protected $attributes = ['version' => 1];

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'is_active' => 'boolean'];
    }
}
