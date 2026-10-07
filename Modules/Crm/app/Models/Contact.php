<?php

namespace Modules\Crm\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer or lead: a person or an organization, at a branch, with consent to marketing kept with its time.
 */
#[Fillable(['organization_id', 'unit_id', 'kind', 'name', 'company_name', 'phone', 'email', 'address', 'tags', 'source', 'owner_id', 'sms_consent', 'email_consent', 'consent_at', 'extra', 'is_active', 'created_by', 'op_id', 'version'])]
class Contact extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const KINDS = ['person', 'organization'];

    protected $table = 'crm_contacts';

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'tags' => 'array',
            'extra' => 'array',
            'sms_consent' => 'boolean',
            'email_consent' => 'boolean',
            'consent_at' => 'immutable_datetime',
            'spent_minor' => 'integer',
            'purchases' => 'integer',
            'last_purchase_on' => 'immutable_date',
            'points' => 'integer',
            'is_active' => 'boolean',
            'anonymized_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
