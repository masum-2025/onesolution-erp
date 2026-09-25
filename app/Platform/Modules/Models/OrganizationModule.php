<?php

namespace App\Platform\Modules\Models;

use App\Platform\Modules\Enums\ModuleState;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module state set at one organization level. Written only through
 * ModuleToggleService (audited). Not tenant-scoped on purpose: resolution
 * must read the rows of every ancestor.
 */
#[Fillable(['organization_id', 'module_key', 'state', 'locked', 'settings', 'reason'])]
class OrganizationModule extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'state' => ModuleState::class,
            'locked' => 'boolean',
            'settings' => 'array',
            'changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
