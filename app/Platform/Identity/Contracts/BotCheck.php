<?php

namespace App\Platform\Identity\Contracts;

/**
 * Tells people from bots on public forms (sign-up, code requests, recovery).
 * Drivers: config identity.bot_check.driver.
 */
interface BotCheck
{
    public function passes(?string $token, ?string $ip): bool;

    /**
     * What the browser needs to show the check (no secrets).
     *
     * @return array{driver: string, site_key: string|null}
     */
    public function publicConfig(): array;

    /**
     * Origins the page must allow (script and frame) for the check to load.
     *
     * @return list<string>
     */
    public function origins(): array;
}
