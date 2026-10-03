<?php

namespace Modules\Hrm\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** One expiry reminder sent for one document (each stage once). */
#[Fillable(['organization_id', 'document_id', 'stage', 'sent_on'])]
class DocumentAlert extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const EXPIRED = -1;

    public const UPDATED_AT = null;

    protected $table = 'hrm_document_alerts';

    protected function casts(): array
    {
        return ['stage' => 'integer', 'sent_on' => 'immutable_date'];
    }
}
