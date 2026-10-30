<?php

namespace Modules\Projects\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A project's quote (CRM) or invoice (Accounting), by id only. */
#[Fillable(['organization_id', 'project_id', 'kind', 'target_id', 'created_by', 'created_at', 'sent_at', 'sent_by', 'client_answer', 'client_reason'])]
class ProjectLink extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const QUOTE = 'quote';

    public const INVOICE = 'invoice';

    public const UPDATED_AT = null;

    protected $table = 'prj_project_links';

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }
}
