<?php

namespace App\Platform\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Security events for the log collector (Phase 8-2): one JSON line per event
 * on the security channel. Carries ids, codes and the request's origin only,
 * never passwords, codes, tokens, secrets or personal data such as email
 * addresses and phone numbers.
 */
class SecurityLog
{
    /** Context keys that are dropped whatever their value. */
    private const SECRET_KEYS = [
        'password', 'secret', 'token', 'code', 'otp', 'key', 'authorization', 'cookie',
        'email', 'phone', 'name', 'store_password', 'recovery', 'credential',
    ];

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $event, array $context = [], string $level = 'info'): void
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        Log::channel((string) config('security.log_channel'))->log($level, $event, [
            'event' => $event,
            ...$this->clean($context),
            ...$this->origin($request),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function clean(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            if (is_string($key) && $this->isSecret($key)) {
                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => $this->clean($value),
                is_string($value) => Str::limit($value, 200, ''),
                is_scalar($value), $value === null => $value,
                default => get_debug_type($value),
            };
        }

        return $clean;
    }

    private function isSecret(string $key): bool
    {
        $key = Str::lower($key);

        foreach (self::SECRET_KEYS as $secret) {
            // "*_id" keys are references, not secrets (e.g. api_key_id).
            if (str_contains($key, $secret) && ! str_ends_with($key, '_id')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function origin(?Request $request): array
    {
        if ($request === null) {
            return ['source' => 'console'];
        }

        // The route's pattern, not the path: paths can hold invitation or reset tokens.
        return [
            'ip' => $request->ip(),
            'method' => $request->method(),
            'route' => $request->route()?->uri(),
            'host' => $request->getHost(),
        ];
    }
}
