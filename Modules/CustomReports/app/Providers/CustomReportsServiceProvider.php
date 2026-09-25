<?php

namespace Modules\CustomReports\Providers;

use Illuminate\Support\ServiceProvider;

class CustomReportsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__, 2).'/lang', 'custom_reports');
    }
}
