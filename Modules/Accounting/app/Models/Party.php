<?php

namespace Modules\Accounting\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer or vendor of the company (or both). Never deleted once it has
 * documents: made inactive instead.
 */
#[Fillable([
    'organization_id', 'name', 'is_customer', 'is_vendor', 'code', 'phone', 'email', 'address',
    'tax_number', 'payment_terms_days', 'crm_contact_id', 'is_active', 'version',
])]
class Party extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'acc_parties';

    protected function casts(): array
    {
        return [
            'is_customer' => 'boolean',
            'is_vendor' => 'boolean',
            'is_active' => 'boolean',
            'address' => 'array',
            'payment_terms_days' => 'integer',
            'version' => 'integer',
        ];
    }
}
