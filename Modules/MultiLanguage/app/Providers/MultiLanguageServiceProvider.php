<?php

namespace Modules\MultiLanguage\Providers;

use Illuminate\Support\ServiceProvider;

class MultiLanguageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__, 2).'/lang', 'multi_language');
    }
}
