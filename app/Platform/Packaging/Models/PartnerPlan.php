<?php

namespace App\Platform\Packaging\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A partner's own plan on top of one of ours: its own name and prices, and
 * optionally fewer modules than the base plan (never more). Partner data,
 * not client data: it has no organization.
 */
#[Table('partner_plans')]
#[Fillable(['base_plan_key', 'name', 'description', 'modules', 'status'])]
class PartnerPlan extends Model
{
    use HasTranslatedTexts, HasUlids;

    /** @var list<string> Data labels in several languages. */
    public array $translatable = ['name', 'description'];

    public const ACTIVE = 'active';

    public const ARCHIVED = 'archived';

    protected function casts(): array
    {
        return [
            'modules' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return HasMany<PartnerPlanPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(PartnerPlanPrice::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** Whether the plan leaves a module of its base plan out. */
    public function excludes(string $moduleKey): bool
    {
        return is_array($this->modules) && ! in_array($moduleKey, $this->modules, true);
    }

    public function label(?string $locale = null): string
    {
        return $this->textIn('name', $locale);
    }

    public function price(string $currency, string $period): ?int
    {
        $price = $this->prices->first(fn (PartnerPlanPrice $price) => $price->currency_code === $currency && $price->period === $period);

        return $price?->amount_minor;
    }
}
