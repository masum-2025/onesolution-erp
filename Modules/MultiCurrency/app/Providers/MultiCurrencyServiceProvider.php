<?php

namespace Modules\MultiCurrency\Providers;

use Illuminate\Support\ServiceProvider;

class MultiCurrencyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__, 2).'/lang', 'multi_currency');
    }
}
