<?php

namespace App\Platform\Modules\Models;

use App\Platform\Modules\Enums\PurgeStatus;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'module_key', 'status', 'requested_by', 'execute_after', 'reason'])]
class ModulePurgeRequest extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'status' => PurgeStatus::class,
            'execute_after' => 'datetime',
            'cancelled_at' => 'datetime',
            'executed_at' => 'datetime',
            'result' => 'array',
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
