<?php

namespace Modules\ClientPortal\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * The portal as a module a client turns on or off (Phase 5C-4). Its logic is
 * platform code (app/Platform/Portal), because every module whose records a
 * portal shows relies on it; this module holds the switch, rules and texts.
 */
class ClientPortalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__, 2).'/lang', 'client_portal');
    }
}
