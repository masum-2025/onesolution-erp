<?php

namespace App\Platform\Offline\Contracts;

use App\Platform\Offline\SyncOperation;
use App\Platform\Offline\SyncResult;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * A kind of record a module lets people change offline (e.g. attendance.mark,
 * inventory.stock_count, accounting.cash_receipt). The module declares it in
 * its manifest ("sync_records") and answers here; the sync pipeline never
 * touches the module's tables.
 *
 * apply() runs inside the person's tenant context, as a fresh request would:
 * the module validates the data, checks its policy, and compares the
 * record's version with the operation's base_version (a stale one is a
 * conflict, never an overwrite). Money kinds are append-only: only creates.
 */
interface SyncableRecords
{
    /** "{module}.{kind}", e.g. "accounting.cash_receipt". */
    public function key(): string;

    /** Money (payments, receipts): append-only, and only with offline_mode.allow_offline_payments. */
    public function isMoney(): bool;

    /**
     * The permission a person needs for an action (create, update, delete).
     */
    public function permission(string $action): string;

    /**
     * Rule keys a device needs offline to do this work (they go into the lease).
     *
     * @return list<string>
     */
    public function rules(): array;

    public function apply(SyncOperation $operation): SyncResult;

    /**
     * What changed in the organization since a moment (null: everything the
     * device may keep), including deletions, for the device to catch up.
     *
     * @return array{records: list<array<string, mixed>>, deleted: list<string>}
     */
    public function changes(Organization $organization, ?CarbonImmutable $since, int $limit): array;
}
