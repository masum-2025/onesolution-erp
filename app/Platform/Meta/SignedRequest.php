<?php

namespace App\Platform\Meta;

use App\Platform\Meta\Exceptions\MetaException;
use App\Platform\Meta\Models\MetaApp;

/**
 * Meta's signed_request (deauthorize and data deletion callbacks):
 * "signature.payload", both base64url, the signature an HMAC-SHA256 of the
 * payload with the app's secret. Anything else is refused.
 */
class SignedRequest
{
    /**
     * @return array<string, mixed> The payload (user_id is the person's id for this app).
     */
    public function read(MetaApp $app, string $signedRequest): array
    {
        $parts = explode('.', $signedRequest, 2);
        if (count($parts) !== 2 || ! $app->isActive()) {
            throw MetaException::badSignedRequest();
        }
        [$signature, $payload] = $parts;
        $expected = hash_hmac('sha256', $payload, (string) $app->app_secret, true);
        if (! hash_equals($expected, $this->decode($signature))) {
            throw MetaException::badSignedRequest();
        }
        $data = json_decode($this->decode($payload), true);
        if (! is_array($data) || strtoupper((string) ($data['algorithm'] ?? '')) !== 'HMAC-SHA256' || ! is_string($data['user_id'] ?? null) || $data['user_id'] === '') {
            throw MetaException::badSignedRequest();
        }

        return $data;
    }

    /** Builds one (tests, and the console's "try the callback" check). */
    public function make(MetaApp $app, array $payload): string
    {
        $body = $this->encode((string) json_encode(['algorithm' => 'HMAC-SHA256', ...$payload]));

        return $this->encode(hash_hmac('sha256', $body, (string) $app->app_secret, true)).'.'.$body;
    }

    private function decode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), false);
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
