<?php

namespace Modules\OnlineOrders\Couriers;

use RuntimeException;

/**
 * The courier refused or could not be reached. The reason is one of
 * unreachable, bad_credentials, refused, bad_answer; the courier's own
 * message (no credentials in it) goes to the person as detail.
 */
class CourierFailed extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?string $detail = null)
    {
        parent::__construct("Courier failed: {$reason}");
    }
}
