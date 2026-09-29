<?php

namespace App\Platform\Offline\Services;

/**
 * Signs and checks offline lease tokens: "{key version}.{payload}.{mac}",
 * base64url, HMAC-SHA256. Keys are versioned (config/offline.php) so they can
 * be rotated without breaking leases already on devices.
 */
class LeaseSigner
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function sign(array $payload): string
    {
        $version = (string) config('offline.current_lease_key');
        $body = self::encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $version.'.'.$body.'.'.self::encode(hash_hmac('sha256', $version.'.'.$body, $this->key($version), true));
    }

    /**
     * The payload of a genuine token; null for anything forged, damaged or
     * signed with a key this server no longer has.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || ! array_key_exists($parts[0], (array) config('offline.lease_keys'))) {
            return null;
        }

        [$version, $body, $mac] = $parts;
        $expected = self::encode(hash_hmac('sha256', $version.'.'.$body, $this->key($version), true));
        if (! hash_equals($expected, $mac)) {
            return null;
        }

        $payload = json_decode((string) self::decode($body), true);

        return is_array($payload) ? $payload : null;
    }

    private function key(string $version): string
    {
        $key = config("offline.lease_keys.{$version}");

        // No key of its own: derived from the app key, never the app key itself.
        return filled($key) ? (string) $key : hash_hmac('sha256', "offline-lease:{$version}", (string) config('app.key'), true);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $text): string|false
    {
        return base64_decode(strtr($text, '-_', '+/'), true);
    }
}
