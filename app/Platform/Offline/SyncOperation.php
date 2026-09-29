<?php

namespace App\Platform\Offline;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * One change a device made offline, as it reaches the module that owns the
 * record. The person and organization come from the verified device and
 * lease, never from the operation itself.
 */
final readonly class SyncOperation
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const DELETE = 'delete';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $opId,
        public string $kind,
        public string $action,
        public ?string $recordId,
        // The record's version the device changed; a newer one on the server is a conflict.
        public ?int $baseVersion,
        public array $data,
        public ?CarbonImmutable $madeAt,
        public User $user,
        public Organization $organization,
    ) {}
}
