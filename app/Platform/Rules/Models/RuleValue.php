<?php

namespace App\Platform\Rules\Models;

use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\Enums\RuleScope;
use App\Platform\Rules\Enums\RuleValueStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A rule value stored at one scope. Written only by RuleService, which keeps
 * the history and audit trail. Not tenant-scoped: resolution reads values of
 * every level of a chain (platform, partner, ancestors).
 */
#[Fillable([
    'rule_key', 'scope_type', 'scope_id', 'country_code', 'mode', 'value',
    'effective_from', 'effective_to', 'version', 'status', 'created_by', 'reason',
])]
class RuleValue extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'scope_type' => RuleScope::class,
            'mode' => RuleMode::class,
            'status' => RuleValueStatus::class,
            'value' => 'json',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'reviewed_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /**
     * Active rows in effect at a moment.
     *
     * @param  Builder<RuleValue>  $query
     */
    #[Scope]
    protected function effectiveAt(Builder $query, CarbonInterface $moment): void
    {
        $query->where('status', RuleValueStatus::Active)
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $moment))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $moment));
    }

    public function isEffectiveAt(CarbonInterface $moment): bool
    {
        return $this->status === RuleValueStatus::Active
            && ($this->effective_from === null || $this->effective_from->lte($moment))
            && ($this->effective_to === null || $this->effective_to->gt($moment));
    }
}
