<?php

namespace Modules\ApiIntegration\Providers;

use Illuminate\Support\ServiceProvider;

class ApiIntegrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__, 2).'/lang', 'api_integration');
    }
}
