<?php

namespace App\Platform\Payments\Contracts;

use App\Models\User;
use App\Platform\Payments\CollectionDue;
use App\Platform\Payments\Models\Payment;
use App\Platform\Tenancy\Models\Organization;

/**
 * Something a client's customers pay it for online (Phase 6), declared by
 * the module that owns the record, e.g. a school fee or a clinic bill:
 * "payment_collectables" => [FeeCollectable::class] in its manifest.
 *
 * The module decides what is owed and by whom; the platform only takes the
 * money into the client's own merchant account and tells the module.
 */
interface CollectableProvider
{
    /** "module.kind", e.g. "school.fee". */
    public function key(): string;

    public function label(?string $locale = null): string;

    /**
     * What this person owes for the record now, inside this organization.
     * null when nothing is due, the record is not there, or the person may
     * not pay it (a parent pays only their own child's fees).
     */
    public function due(Organization $organization, string $id, User $payer): ?CollectionDue;

    /**
     * The gateway confirmed the payment: mark the record paid. Runs inside the
     * confirmation's transaction; throwing leaves the payment unconfirmed and
     * the gateway's next message tries again. Must be safe to call once per
     * payment only (the platform guarantees that).
     */
    public function paid(Payment $payment): void;

    /** Where the payer's browser goes after the gateway page: a path in this app, "/...". */
    public function returnPath(Payment $payment): string;
}
