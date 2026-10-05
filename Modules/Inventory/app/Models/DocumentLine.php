<?php

namespace Modules\Inventory\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A line of an inventory document. */
#[Fillable(['organization_id', 'document_id', 'line_no', 'item_id', 'quantity_milli', 'received_milli', 'unit_cost_minor', 'batch_number', 'expires_on', 'value_minor', 'note'])]
class DocumentLine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public $timestamps = false;

    protected $table = 'inv_document_lines';

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'quantity_milli' => 'integer',
            'received_milli' => 'integer',
            'unit_cost_minor' => 'integer',
            'expires_on' => 'immutable_date',
            'value_minor' => 'integer',
        ];
    }
}
