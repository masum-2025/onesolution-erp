<?php

namespace Modules\Expenses\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A receipt (picture or PDF) of a claim, on the private disk; shown through short signed links only. */
#[Fillable(['organization_id', 'claim_id', 'line_id', 'name', 'mime', 'size_bytes', 'path', 'uploaded_by'])]
class Receipt extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'exp_receipts';

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }
}
