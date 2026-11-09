<?php

namespace Modules\OnlineOrders\Couriers;

/**
 * SC-5b: one delivery company's API (Steadfast first). A driver only talks
 * to the courier with the company's own credentials; which couriers a
 * company may use is the rule online_orders.couriers, and what happens to
 * the order is Couriers' and Orders' business.
 *
 * Statuses come back as one of CourierStatus::ALL, never the courier's own words.
 */
interface CourierDriver
{
    public function key(): string;

    /** @return list<string> Countries (ISO codes) the courier delivers in. */
    public function countries(): array;

    /** @return list<string> The credential fields the company types in (all required, kept encrypted). */
    public function credentialFields(): array;

    /**
     * Book a parcel with the courier.
     *
     * @param  array<string, string>  $credentials
     *
     * @throws CourierFailed when the courier refuses or cannot be reached
     */
    public function book(array $credentials, Parcel $parcel): Booking;

    /**
     * Where a parcel stands now (CourierStatus::*).
     *
     * @param  array<string, string>  $credentials
     *
     * @throws CourierFailed
     */
    public function status(array $credentials, string $consignment): string;

    /**
     * A status notice the courier sent to the webhook: the consignment and its status, or null when it is
     * not a status notice (it is then answered and ignored).
     *
     * @param  array<string, mixed>  $payload
     * @return array{consignment: string, status: string, note: ?string}|null
     */
    public function readNotice(array $payload): ?array;
}
