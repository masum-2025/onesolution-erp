<?php

namespace Modules\Leave\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's kind of leave: paid or not, how it comes (all at the leave
 * year's start, a twelfth a month, or a day for every so many days at
 * work), how much may be carried into the next year, and who may take it.
 * Amounts are half days. Switched off, never deleted, once used.
 */
#[Fillable([
    'organization_id', 'code', 'paid', 'accrual', 'allowance_halves', 'worked_days_per_day', 'carry_max_halves',
    'half_day', 'counts_days_off', 'document_after_days', 'gender', 'encashable', 'is_active', 'sort_order', 'version',
])]
class LeaveType extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const ACCRUALS = ['yearly', 'monthly', 'worked_days'];

    protected $table = 'lv_types';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'paid' => 'boolean', 'allowance_halves' => 'integer', 'worked_days_per_day' => 'integer', 'carry_max_halves' => 'integer',
            'half_day' => 'boolean', 'counts_days_off' => 'boolean', 'document_after_days' => 'integer', 'encashable' => 'boolean',
            'is_active' => 'boolean', 'sort_order' => 'integer', 'version' => 'integer',
        ];
    }

    /** Whether someone HR recorded with this gender may take it. */
    public function openTo(?string $gender): bool
    {
        return $this->gender === null || $this->gender === $gender;
    }
}
