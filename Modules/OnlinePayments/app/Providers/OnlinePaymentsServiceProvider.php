<?php

namespace Modules\OnlinePayments\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * A client's own payment gateway accounts as a module it turns on or off
 * (Phase 6). The logic is platform code (app/Platform/Payments), because
 * every module that collects money relies on it; this module holds the
 * switch, rules and texts.
 */
class OnlinePaymentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__, 2).'/lang', 'online_payments');
    }
}
