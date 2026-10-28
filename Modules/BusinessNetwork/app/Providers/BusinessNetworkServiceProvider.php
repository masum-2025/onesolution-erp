<?php

namespace Modules\BusinessNetwork\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Business connections as a module a client turns on or off (NET-1). Its
 * logic is platform code (app/Platform/Connections), because every module
 * that exchanges work with another client relies on it; this module holds
 * the switch, rules, texts and screens.
 */
class BusinessNetworkServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__, 2).'/lang', 'business_network');
    }
}
