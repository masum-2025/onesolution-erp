<?php

namespace App\Platform\Ai;

use App\Platform\Ai\Contracts\ReadsText;
use Illuminate\Support\ServiceProvider;

/** SC-4: the platform's one door to a language model (config/ai.php). */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReadsText::class, fn () => new ClaudeReader(
            config('ai.api_key'),
            (int) config('ai.timeout', 8),
            (int) config('ai.max_tokens', 1024),
        ));
    }
}
