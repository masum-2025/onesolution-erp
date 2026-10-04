<?php

namespace Modules\Accounting\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Enums\JournalStatus;

/**
 * The balances a company brings from its old books: one set per company,
 * written as a draft, sent (and approved above the approval amount), then
 * posted once as one journal. Corrections afterwards are ordinary entries.
 */
#[Fillable(['organization_id', 'opening_date', 'status', 'created_by', 'version'])]
class Opening extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'acc_openings';

    protected function casts(): array
    {
        return [
            'opening_date' => 'immutable_date',
            'status' => JournalStatus::class,
            'submitted_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
