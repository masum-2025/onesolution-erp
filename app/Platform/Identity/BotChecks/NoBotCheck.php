<?php

namespace App\Platform\Identity\BotChecks;

use App\Platform\Identity\Contracts\BotCheck;

/**
 * No check. Open in local development and tests; in production it refuses
 * everyone (driver "blocked"), so public sign-up stays closed until a real
 * check is configured.
 */
class NoBotCheck implements BotCheck
{
    public function __construct(private bool $open = true) {}

    public function passes(?string $token, ?string $ip): bool
    {
        return $this->open;
    }

    public function publicConfig(): array
    {
        return ['driver' => $this->open ? 'none' : 'blocked', 'site_key' => null];
    }

    public function origins(): array
    {
        return [];
    }
}
