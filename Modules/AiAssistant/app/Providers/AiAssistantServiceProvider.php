<?php

namespace Modules\AiAssistant\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The AI assistant (SC-4): other modules ask its Understanding service to
 * read text; the company sees how much it used. Needs consent to turn on.
 */
class AiAssistantServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'ai_assistant');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
