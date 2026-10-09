<?php

namespace App\Platform\Localization;

use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;

/**
 * A level that can word texts its own way: the platform, a partner (for all
 * of its clients), or an organization (a group or a company, for everyone in
 * it). Built from server-side models only, never from request input.
 */
final class TranslationScope
{
    public const PLATFORM = 'platform';

    public const PARTNER = 'partner';

    public const ORGANIZATION = 'organization';

    private function __construct(public readonly string $type, public readonly string $id) {}

    public static function platform(): self
    {
        return new self(self::PLATFORM, '');
    }

    public static function partner(Partner $partner): self
    {
        return new self(self::PARTNER, (string) $partner->getKey());
    }

    public static function organization(Organization $organization): self
    {
        return new self(self::ORGANIZATION, (string) $organization->getKey());
    }

    /** A level read back from a stored row (server data, not request input). */
    public static function fromStored(string $type, string $id): self
    {
        return new self($type, $type === self::PLATFORM ? '' : $id);
    }

    /** "platform", "partner:01J…", "organization:01J…": cache keys and hashes. */
    public function key(): string
    {
        return $this->id === '' ? $this->type : "{$this->type}:{$this->id}";
    }

    public function isPlatform(): bool
    {
        return $this->type === self::PLATFORM;
    }
}
