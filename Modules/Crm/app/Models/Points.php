<?php

namespace Modules\Crm\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Loyalty points earned or given back by a sale or return (append-only, once per source).
 */
#[Fillable(['organization_id', 'contact_id', 'points', 'reason', 'source_module', 'source_type', 'source_id'])]
class Points extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    protected $table = 'crm_points';

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
