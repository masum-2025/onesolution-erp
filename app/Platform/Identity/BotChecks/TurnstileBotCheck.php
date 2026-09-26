<?php

namespace App\Platform\Identity\BotChecks;

use App\Platform\Identity\Contracts\BotCheck;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cloudflare Turnstile: the browser gets a token, the server confirms it
 * once with Cloudflare. If Cloudflare cannot be reached, the check fails
 * (closed), so bots cannot slip through an outage.
 */
class TurnstileBotCheck implements BotCheck
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(private string $siteKey, private string $secret) {}

    public function passes(?string $token, ?string $ip): bool
    {
        if ($token === null || $token === '' || strlen($token) > 2048) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, array_filter([
                'secret' => $this->secret,
                'response' => $token,
                'remoteip' => $ip,
            ]));

            return $response->successful() && $response->json('success') === true;
        } catch (Throwable $exception) {
            Log::warning('Bot check unreachable', ['error' => class_basename($exception)]);

            return false;
        }
    }

    public function publicConfig(): array
    {
        return ['driver' => 'turnstile', 'site_key' => $this->siteKey];
    }

    public function origins(): array
    {
        return ['https://challenges.cloudflare.com'];
    }
}
