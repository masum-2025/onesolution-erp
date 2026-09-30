<?php

namespace App\Platform\Audit\Shipping\Contracts;

/**
 * Sends audit entries to an external, append-only store (Phase 9-1). Entries
 * may arrive more than once after a failure; each carries its id, so the
 * store can ignore repeats.
 */
interface AuditShipper
{
    /** A short, stable name; the cursor of what was sent is kept under it. */
    public function name(): string;

    /**
     * @param  list<array<string, mixed>>  $entries  Oldest first.
     */
    public function ship(array $entries): void;
}
