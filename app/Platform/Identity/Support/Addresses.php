<?php

namespace App\Platform\Identity\Support;

use Illuminate\Support\Str;

/**
 * Keyed hashes of addresses, IPs and codes: limits and checks work on them,
 * and a database leak shows none of the originals.
 */
final class Addresses
{
    public static function hash(string $value): string
    {
        return hash_hmac('sha256', Str::lower(trim($value)), (string) config('app.key'));
    }

    public static function codeHash(string $challengeId, string $code): string
    {
        return hash_hmac('sha256', $challengeId.'|'.$code, (string) config('app.key'));
    }

    public static function isDisposable(string $email): bool
    {
        $domain = Str::lower(Str::after($email, '@'));
        if ($domain === '') {
            return false;
        }

        foreach (self::disposableDomains() as $blocked) {
            if ($domain === $blocked || str_ends_with($domain, '.'.$blocked)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function disposableDomains(): array
    {
        static $domains = null;

        if ($domains === null) {
            $file = (string) config('identity.disposable_domains_file');
            $lines = is_file($file) ? (array) file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
            $domains = array_values(array_filter(array_map(fn ($line) => Str::lower(trim((string) $line)), $lines), fn ($line) => $line !== '' && ! str_starts_with($line, '#')));
        }

        return $domains;
    }
}
