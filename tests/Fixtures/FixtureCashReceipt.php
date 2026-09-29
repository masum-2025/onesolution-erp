<?php

namespace Tests\Fixtures;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Stand-in for a money record made offline: append-only (Phase 7 tests).
 */
#[Fillable(['organization_id', 'amount_minor', 'currency_code', 'version'])]
class FixtureCashReceipt extends Model
{
    use BelongsToOrganization, HasUlids;
}
