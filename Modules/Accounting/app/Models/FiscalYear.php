<?php

namespace Modules\Accounting\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Twelve months of a company's books, from its fiscal year start
 * (rule accounting.fiscal_year_start), split into monthly periods. A closed
 * year has its income and expenses moved into retained earnings by a closing
 * entry (in its closing period); reopening it reverses that entry.
 */
#[Fillable(['organization_id', 'name', 'starts_on', 'ends_on', 'status'])]
class FiscalYear extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $table = 'acc_fiscal_years';

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'closed_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }

    /**
     * @return HasMany<Period, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(Period::class)->orderBy('number');
    }
}
