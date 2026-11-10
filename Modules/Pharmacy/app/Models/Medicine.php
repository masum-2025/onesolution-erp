<?php

namespace Modules\Pharmacy\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * What makes an Inventory item a medicine: generic, strength, form, DGDA no., MRP of one piece and its packs.
 */
#[Fillable(['organization_id', 'unit_id', 'item_id', 'generic_id', 'manufacturer_id', 'strength', 'form', 'dgda_no', 'mrp_minor', 'currency', 'pieces_per_strip', 'strips_per_box', 'requires_prescription', 'is_active', 'created_by', 'op_id', 'version'])]
class Medicine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'pharmacy_medicines';

    public const FORMS = ['tablet', 'capsule', 'syrup', 'suspension', 'injection', 'drops', 'cream', 'ointment', 'inhaler', 'powder', 'suppository', 'other'];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'mrp_minor' => 'integer', 'pieces_per_strip' => 'integer', 'strips_per_box' => 'integer', 'requires_prescription' => 'boolean', 'is_active' => 'boolean'];
    }
}
