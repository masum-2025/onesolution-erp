<?php

namespace App\Platform\Tenancy\Databases;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which database holds one client tree's business data. Written only by
 * TenantPlacements (audited); no row means the main database.
 *
 * @property string $root_organization_id
 * @property DatabaseStrategy $strategy
 * @property string|null $database
 * @property PlacementStatus $status
 */
#[Fillable([
    'root_organization_id', 'strategy', 'database', 'status', 'status_changed_at',
    'previous_database', 'previous_retained_until',
])]
class TenantPlacement extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'strategy' => DatabaseStrategy::class,
            'status' => PlacementStatus::class,
            'status_changed_at' => 'immutable_datetime',
            'previous_retained_until' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function root(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'root_organization_id');
    }
}
