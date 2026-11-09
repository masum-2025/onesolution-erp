<?php

namespace App\Platform\Payments\Contracts;

use App\Platform\Payments\CollectionDue;
use App\Platform\Payments\PaymentCustomer;
use App\Platform\Tenancy\Models\Organization;

/**
 * A record a customer without a sign-in may pay (SC-5a): an online order's
 * customer opens a short-lived signed link sent to them (Messenger). Only
 * for providers that also implement CollectableProvider; the link is the
 * proof, so the module must give the amount from the record alone.
 */
interface GuestCollectable
{
    /** What is to be paid for the record now; null when nothing is (paid, cancelled, turned off). */
    public function dueForGuest(Organization $organization, string $id): ?CollectionDue;

    /** Who pays, from the record (name, phone), for the gateway's page. */
    public function guestCustomer(Organization $organization, string $id): PaymentCustomer;
}
