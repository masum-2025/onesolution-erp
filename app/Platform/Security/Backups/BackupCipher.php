<?php

namespace App\Platform\Security\Backups;

use SodiumException;

/**
 * Encrypts backup files with libsodium's secretstream (XChaCha20-Poly1305),
 * in chunks so any size fits in memory. Every chunk is authenticated and the
 * last one is marked, so a changed, reordered or cut-off file is refused.
 *
 * Keys are versioned: the file starts with the version of the key that wrote
 * it, so a new key (BACKUP_KEY_VERSION) can be introduced while older sets
 * stay readable with their own key.
 *
 * File: "OSBK1" | version length (1 byte) | version | stream header | chunks,
 * each chunk = ciphertext length (4 bytes, big endian) | ciphertext.
 */
class BackupCipher
{
    private const MAGIC = 'OSBK1';

    private const CHUNK_BYTES = 1048576;

    public function currentVersion(): string
    {
        return (string) config('security.backups.key_version');
    }

    public function encrypt(string $plainPath, string $encryptedPath): void
    {
        $version = $this->currentVersion();
        $key = $this->key($version);
        $in = fopen($plainPath, 'rb');
        $out = fopen($encryptedPath, 'wb');

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            fwrite($out, self::MAGIC.chr(strlen($version)).$version.$header);

            $chunk = (string) fread($in, self::CHUNK_BYTES);
            do {
                $next = feof($in) ? '' : (string) fread($in, self::CHUNK_BYTES);
                $last = $next === '' && feof($in);
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $chunk,
                    '',
                    $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                );
                fwrite($out, pack('N', strlen($cipher)).$cipher);
                $chunk = $next;
            } while (! $last);
        } finally {
            fclose($in);
            fclose($out);
            sodium_memzero($key);
        }
    }

    /**
     * @return string The version of the key that had written the file.
     */
    public function decrypt(string $encryptedPath, string $plainPath, string $label): string
    {
        $in = fopen($encryptedPath, 'rb');
        $out = fopen($plainPath, 'wb');

        try {
            if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw BackupException::unreadable($label);
            }

            $version = (string) fread($in, ord((string) fread($in, 1)));
            $key = $this->key($version);
            $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

            try {
                $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            } catch (SodiumException) {
                throw BackupException::unreadable($label);
            } finally {
                sodium_memzero($key);
            }

            do {
                $length = fread($in, 4);
                if ($length === false || strlen($length) !== 4) {
                    // Cut off before the final chunk.
                    throw BackupException::unreadable($label);
                }

                $cipher = (string) fread($in, unpack('N', $length)[1]);
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($result === false) {
                    throw BackupException::unreadable($label);
                }

                [$plain, $tag] = $result;
                fwrite($out, $plain);
            } while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);

            // Nothing may follow the final chunk.
            if (fread($in, 1) !== '') {
                throw BackupException::unreadable($label);
            }

            return $version;
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * Signs a manifest with the key of its version, so its checksums and
     * counts cannot be changed without the key.
     */
    public function sign(string $payload, string $version): string
    {
        $key = $this->key($version);

        try {
            return hash_hmac('sha256', $payload, $key);
        } finally {
            sodium_memzero($key);
        }
    }

    public function verify(string $payload, string $signature, string $version): bool
    {
        return hash_equals($this->sign($payload, $version), $signature);
    }

    /** A new random key, base64, for BACKUP_KEY_V*. */
    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    private function key(string $version): string
    {
        // The version comes from the file itself: a plain name only, never a config path.
        if (preg_match('/^[a-z0-9_]{1,16}$/', $version) !== 1) {
            throw BackupException::noKey('?');
        }

        $encoded = config("security.backups.keys.{$version}");

        if (! is_string($encoded) || $encoded === '') {
            throw BackupException::noKey($version);
        }

        $key = base64_decode(str_starts_with($encoded, 'base64:') ? substr($encoded, 7) : $encoded, true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw BackupException::badKey($version);
        }

        return $key;
    }
}
