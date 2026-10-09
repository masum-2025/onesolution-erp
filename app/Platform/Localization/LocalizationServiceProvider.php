<?php

namespace App\Platform\Localization;

use App\Platform\Localization\Console\ExportLanguage;
use App\Platform\Localization\Console\MissingTexts;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\Translator;

/**
 * Languages and wording in the database (LANG-1), on top of the files:
 * the language list, the editor, and Laravel's translator reading the
 * platform's, partner's and organization's own wording.
 */
class LocalizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Kept for one request or job: languages and chains change at runtime.
        $this->app->scoped(LanguageRegistry::class);
        $this->app->scoped(ServerOverlay::class);
        $this->app->singleton(TranslationCatalog::class);

        $this->app->extend('translator', fn (Translator $translator) => OverlayTranslator::wrap($translator));
    }

    public function boot(): void
    {
        // Text bundles are cached by the browser under their hash; a page load asks once or twice.
        RateLimiter::for('i18n', fn (Request $request) => Limit::perMinute(120)->by('i18n:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // A translator's file: a few per minute is plenty.
        RateLimiter::for('i18n-import', fn (Request $request) => Limit::perMinute(5)->by('i18n-import:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        if ($this->app->runningInConsole()) {
            $this->commands([ExportLanguage::class, MissingTexts::class]);
        }
    }
}
