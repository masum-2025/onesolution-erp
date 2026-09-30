<?php

namespace Tests\Fixtures;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Stand-in for a module record changed offline (Phase 7 tests).
 */
#[Fillable(['organization_id', 'title', 'version'])]
class FixtureSyncNote extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes, UsesTenantDatabase;
}
