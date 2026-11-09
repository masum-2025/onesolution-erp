<?php

namespace Modules\OnlineOrders\Couriers;

/**
 * The couriers the platform can talk to. A new courier is a new driver
 * here (and in the rule's list); which ones a company may use is the rule
 * online_orders.couriers and its country.
 */
class CourierDrivers
{
    /** @var array<string, class-string<CourierDriver>> */
    public const DRIVERS = [
        SteadfastDriver::KEY => SteadfastDriver::class,
    ];

    public function has(string $key): bool
    {
        return isset(self::DRIVERS[$key]);
    }

    public function get(string $key): CourierDriver
    {
        return app(self::DRIVERS[$key]);
    }
}
