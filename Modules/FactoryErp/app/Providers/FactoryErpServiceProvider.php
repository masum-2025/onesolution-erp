<?php

namespace Modules\FactoryErp\Providers;

use Illuminate\Support\ServiceProvider;

class FactoryErpServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__, 2).'/lang', 'factory_erp');
    }
}
