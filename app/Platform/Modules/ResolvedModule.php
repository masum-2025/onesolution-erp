<?php

namespace App\Platform\Modules;

use App\Platform\Modules\Enums\ModuleState;
use App\Platform\Modules\Enums\ResolutionReason;

/**
 * The outcome of resolving one module for one organization, including
 * where the decision came from (for "inherited from X / locked by Y" in the UI).
 */
final readonly class ResolvedModule
{
    /**
     * @param  list<string>  $blockedBy  Required modules that are not enabled.
     */
    public function __construct(
        public string $key,
        public bool $enabled,
        public bool $available,
        public ResolutionReason $reason,
        public ModuleState $state,
        /** 'self' | 'inherited' | 'partner' | 'default' */
        public string $source,
        public ?string $sourceOrganizationId,
        public ?string $lockedByOrganizationId,
        public bool $lockedHere,
        public array $blockedBy,
        /** The partner locked the module for all of its clients. */
        public bool $lockedByPartner = false,
    ) {}

    public function isLockedByAncestor(): bool
    {
        return $this->lockedByOrganizationId !== null || $this->lockedByPartner;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'enabled' => $this->enabled,
            'available' => $this->available,
            'reason' => $this->reason->value,
            'state' => $this->state->value,
            'source' => $this->source,
            'source_organization_id' => $this->sourceOrganizationId,
            'locked_by_organization_id' => $this->lockedByOrganizationId,
            'locked_here' => $this->lockedHere,
            'blocked_by' => $this->blockedBy,
            'locked_by_partner' => $this->lockedByPartner,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: $data['key'],
            enabled: $data['enabled'],
            available: $data['available'],
            reason: ResolutionReason::from($data['reason']),
            state: ModuleState::from($data['state']),
            source: $data['source'],
            sourceOrganizationId: $data['source_organization_id'],
            lockedByOrganizationId: $data['locked_by_organization_id'],
            lockedHere: $data['locked_here'],
            blockedBy: $data['blocked_by'],
            lockedByPartner: (bool) ($data['locked_by_partner'] ?? false),
        );
    }
}
