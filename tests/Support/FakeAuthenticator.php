<?php

namespace Tests\Support;

use RuntimeException;

/**
 * A software passkey for tests (Phase 8-1): an EC P-256 key pair that
 * answers navigator.credentials.create() and .get() exactly like a device
 * that verified the person (user present + verified), with "none"
 * attestation. The server checks the answers with the real WebAuthn library.
 */
class FakeAuthenticator
{
    private const FLAG_USER_PRESENT = 0x01;

    private const FLAG_USER_VERIFIED = 0x04;

    private const FLAG_ATTESTED = 0x40;

    public string $credentialId;

    private \OpenSSLAsymmetricKey $key;

    private int $counter = 0;

    private ?string $userHandle = null;

    public function __construct()
    {
        $options = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $key = openssl_pkey_new($options);
        if ($key === false) {
            // Windows PHP builds find no openssl.cnf by default: use the one shipped with PHP.
            $config = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
            $key = is_file($config) ? openssl_pkey_new([...$options, 'config' => $config]) : false;
        }
        if ($key === false) {
            throw new RuntimeException('OpenSSL could not make a P-256 key.');
        }

        $this->key = $key;
        $this->credentialId = random_bytes(32);
    }

    /**
     * The answer to navigator.credentials.create(options), as the browser sends it (JSON form).
     *
     * @param  array<string, mixed>  $options  What the server returned for the ceremony.
     * @return array<string, mixed>
     */
    public function create(array $options, string $origin): array
    {
        $this->userHandle = self::decode($options['user']['id']);
        $clientData = $this->clientData('webauthn.create', $options['challenge'], $origin);

        $details = openssl_pkey_get_details($this->key);
        $coseKey = self::cborMap([
            [1, 2],            // kty: EC2
            [3, -7],           // alg: ES256
            [-1, 1],           // crv: P-256
            [-2, self::bytes(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT))],
            [-3, self::bytes(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT))],
        ]);

        $authData = hash('sha256', $options['rp']['id'], true)
            .chr(self::FLAG_USER_PRESENT | self::FLAG_USER_VERIFIED | self::FLAG_ATTESTED)
            .pack('N', $this->counter)
            .str_repeat("\0", 16)
            .pack('n', strlen($this->credentialId)).$this->credentialId
            .$coseKey;

        $attestation = self::cborMap([
            ['fmt', 'none'],
            ['attStmt', (object) ['raw' => self::cborMap([])]],
            ['authData', self::bytes($authData)],
        ]);

        return [
            'id' => self::encode($this->credentialId),
            'rawId' => self::encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::encode($clientData),
                'attestationObject' => self::encode($attestation),
                'transports' => ['internal'],
            ],
            'clientExtensionResults' => [],
        ];
    }

    /**
     * The answer to navigator.credentials.get(options).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function get(array $options, string $origin, ?string $rpId = null): array
    {
        $this->counter++;
        $clientData = $this->clientData('webauthn.get', $options['challenge'], $origin);
        $authData = hash('sha256', $rpId ?? $options['rpId'], true)
            .chr(self::FLAG_USER_PRESENT | self::FLAG_USER_VERIFIED)
            .pack('N', $this->counter);

        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => self::encode($this->credentialId),
            'rawId' => self::encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::encode($clientData),
                'authenticatorData' => self::encode($authData),
                'signature' => self::encode($signature),
                'userHandle' => $this->userHandle === null ? null : self::encode($this->userHandle),
            ],
            'clientExtensionResults' => [],
        ];
    }

    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): string
    {
        return (string) base64_decode(strtr($encoded, '-_', '+/'));
    }

    private function clientData(string $type, string $challenge, string $origin): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
    }

    // ── A minimal CBOR encoder: unsigned/negative integers, byte and text strings, maps ──

    /** Marks a PHP string as a CBOR byte string (text strings stay plain strings). */
    private static function bytes(string $raw): object
    {
        return (object) ['bytes' => $raw];
    }

    /**
     * @param  list<array{0: int|string, 1: mixed}>  $pairs
     */
    private static function cborMap(array $pairs): string
    {
        $out = self::head(5, count($pairs));
        foreach ($pairs as [$key, $value]) {
            $out .= self::cbor($key).self::cbor($value);
        }

        return $out;
    }

    private static function cbor(mixed $value): string
    {
        return match (true) {
            is_int($value) && $value >= 0 => self::head(0, $value),
            is_int($value) => self::head(1, -1 - $value),
            // Already encoded (a nested map).
            is_object($value) && isset($value->raw) => $value->raw,
            is_object($value) => self::head(2, strlen($value->bytes)).$value->bytes,
            is_string($value) => self::head(3, strlen($value)).$value,
            default => throw new RuntimeException('Unsupported CBOR value.'),
        };
    }

    private static function head(int $major, int $length): string
    {
        return match (true) {
            $length < 24 => chr(($major << 5) | $length),
            $length < 256 => chr(($major << 5) | 24).chr($length),
            $length < 65536 => chr(($major << 5) | 25).pack('n', $length),
            default => chr(($major << 5) | 26).pack('N', $length),
        };
    }
}
