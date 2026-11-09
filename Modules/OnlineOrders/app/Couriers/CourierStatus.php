<?php

namespace Modules\OnlineOrders\Couriers;

/** Where a parcel stands, in our own words (each driver maps the courier's). */
final class CourierStatus
{
    public const BOOKED = 'booked';

    public const IN_TRANSIT = 'in_transit';

    public const ON_HOLD = 'on_hold';

    public const DELIVERED = 'delivered';

    public const PARTLY_DELIVERED = 'partly_delivered';

    public const CANCELLED = 'cancelled';

    public const UNKNOWN = 'unknown';

    public const ALL = [self::BOOKED, self::IN_TRANSIT, self::ON_HOLD, self::DELIVERED, self::PARTLY_DELIVERED, self::CANCELLED, self::UNKNOWN];
}
