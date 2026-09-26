<?php

namespace App\Platform\PartnerApi\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A key a partner's own systems (website, CRM) use to provision clients,
 * members and plans. The full key is shown once; only a hash of it is kept.
 * Actions through the key are made in its creator's name.
 */
#[Table('partner_api_keys')]
#[Hidden(['secret_hash'])]
class PartnerApiKey extends Model
{
    use HasUlids;

    public const SCOPES = ['clients:read', 'clients:write', 'members:write', 'plans:read', 'subscriptions:write'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, (array) $this->scopes, true);
    }

    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'active',
        };
    }
}
