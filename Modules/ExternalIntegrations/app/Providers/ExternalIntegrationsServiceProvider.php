<?php

namespace Modules\ExternalIntegrations\Providers;

use Illuminate\Support\ServiceProvider;

class ExternalIntegrationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__, 2).'/lang', 'external_integrations');
    }
}
