<?php

namespace App\Platform\Packaging\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Database mirror of the PlanCatalog (packaging:sync). Platform data.
 */
#[Table('plans')]
#[Fillable(['key', 'audience', 'modules', 'is_public', 'sort_order', 'deprecated_at'])]
class Plan extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['modules' => 'array', 'is_public' => 'boolean', 'deprecated_at' => 'datetime'];
    }

    /**
     * @return HasMany<PlanPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }
}
