<?php

namespace Modules\OfflineMode\Providers;

use Illuminate\Support\ServiceProvider;

class OfflineModeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__, 2).'/lang', 'offline_mode');
    }
}
