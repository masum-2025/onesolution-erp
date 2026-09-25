<?php

namespace Modules\CarbonManagement\Providers;

use Illuminate\Support\ServiceProvider;

class CarbonManagementServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__, 2).'/lang', 'carbon_management');
    }
}
