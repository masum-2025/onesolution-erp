<?php

namespace Modules\EnergyMonitoring\Providers;

use Illuminate\Support\ServiceProvider;

class EnergyMonitoringServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__, 2).'/lang', 'energy_monitoring');
    }
}
