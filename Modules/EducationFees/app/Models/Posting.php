<?php

namespace Modules\EducationFees\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * What still has to reach the books, posted once by a queued job (op id fee-<kind>-<id>).
 */
#[Fillable(['organization_id', 'kind', 'source_id', 'status', 'journal_id', 'attempts', 'error', 'posted_at'])]
class Posting extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const KINDS = ['bill', 'fine', 'receipt', 'advance', 'void', 'cancel', 'refund'];

    protected $table = 'fee_postings';

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'posted_at' => 'immutable_datetime'];
    }
}
