<?php

namespace App\Platform\Connections;

use App\Platform\Tenancy\Models\Organization;

/**
 * One message as the receiving module gets it: who it is from (by name
 * only), the unit it is for on the receiver's side, the payload, and the
 * files already copied into the receiver's own storage.
 */
final readonly class ConnectionDelivery
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{path: string, name: string, mime: string, size: int, sha256: string}>  $files  On the receiver's side, on the "local" (private) disk.
     */
    public function __construct(
        public string $messageId,
        public string $connectionId,
        public string $kind,
        public Organization $to,
        public string $fromUnitId,
        public string $fromName,
        public array $payload,
        public array $files,
    ) {}
}
