<?php

namespace Tests\Fixtures;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Stand-in for a business model (invoice, student, payslip...) in tests. Its
 * rows follow the client into a dedicated database (Phase 10).
 */
#[Fillable(['title', 'organization_id'])]
class TenantNote extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;
}
