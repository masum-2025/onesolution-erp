<?php

namespace App\Platform\Connections\Models;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of the client's companies or branches (a factory) the provider may deal
 * with, and the units below it. Removed ones keep their row (removed_at).
 * Nothing is mass assignable.
 */
#[Table('business_connection_units')]
class ConnectionUnit extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['removed_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'unit_id');
    }
}
