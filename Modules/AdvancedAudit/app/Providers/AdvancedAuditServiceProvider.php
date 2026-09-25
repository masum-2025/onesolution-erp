<?php

namespace Modules\AdvancedAudit\Providers;

use Illuminate\Support\ServiceProvider;

class AdvancedAuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__, 2).'/lang', 'advanced_audit');
    }
}
