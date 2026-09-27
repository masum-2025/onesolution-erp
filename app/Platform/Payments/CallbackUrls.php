<?php

namespace App\Platform\Payments;

/**
 * Where the gateway sends the browser back and posts its notification.
 */
final readonly class CallbackUrls
{
    public function __construct(
        public string $success,
        public string $fail,
        public string $cancel,
        public string $notify,
    ) {}
}
