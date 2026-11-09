<?php

namespace Modules\OnlineOrders\Couriers;

/** The courier's answer to a booking: its own id for the parcel and the code customers track it with. */
final readonly class Booking
{
    public function __construct(
        public string $consignment,
        public string $tracking,
        public string $status = CourierStatus::BOOKED,
    ) {}
}
