<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Spatie\Translatable\Translatable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A data label missing in the reader's language falls back to the app
        // fallback language, then to any language it has (never blank).
        $this->app->make(Translatable::class)->fallback(fallbackAny: true);
    }
}
