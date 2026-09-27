<?php

namespace App\Platform\Payments\Contracts;

/**
 * Builds a gateway for one account's credentials (Phase 6): the platform's
 * own (from config) or a client's merchant account. Also tells the screen
 * what a merchant account for this gateway needs, and checks credentials
 * with the gateway before anyone relies on them.
 */
interface GatewayDriver
{
    /** The gateway's key, as in rules and URLs, e.g. "sslcommerz". */
    public function key(): string;

    /**
     * What a person enters to connect an account: field => [secret, validation rules].
     * Secret fields are never shown again, not even to the person who typed them.
     *
     * @return array<string, array{secret: bool, rules: list<string>}>
     */
    public function credentialFields(): array;

    /**
     * @return list<string> ISO currency codes the gateway takes.
     */
    public function currencies(): array;

    /**
     * A short, safe way to recognise the account on screen (never a secret).
     *
     * @param  array<string, string>  $credentials
     */
    public function hint(array $credentials): string;

    /**
     * @param  array<string, string>  $credentials
     * @param  string  $mode  sandbox | live
     */
    public function make(array $credentials, string $mode): PaymentGateway;

    /**
     * Asks the gateway whether it accepts these credentials: "ok", or an
     * error code (rejected_credentials, store_inactive, unreachable, unexpected).
     *
     * @param  array<string, string>  $credentials
     */
    public function check(array $credentials, string $mode): string;
}
