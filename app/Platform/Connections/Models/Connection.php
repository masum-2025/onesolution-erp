<?php

namespace App\Platform\Connections\Models;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A business connection between a service provider (a company) and its
 * client (a group or a company): invited → active ⇄ paused → ended.
 * Nothing is mass assignable.
 *
 * Not tenant-scoped on purpose: it belongs to two clients at once. Every
 * read goes through Services\Connections, which keeps each side to its own.
 */
#[Table('business_connections')]
class Connection extends Model
{
    use HasUlids;

    public const INVITED = 'invited';

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const ENDED = 'ended';

    public const PROVIDER = 'provider';

    public const CLIENT = 'client';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'provider_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'client_id');
    }

    /**
     * @return HasMany<ConnectionUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(ConnectionUnit::class, 'connection_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** provider | client | null: which side an organization (the side itself) is. */
    public function sideOf(string $organizationId): ?string
    {
        return match ($organizationId) {
            $this->provider_id => self::PROVIDER,
            $this->client_id => self::CLIENT,
            default => null,
        };
    }
}
