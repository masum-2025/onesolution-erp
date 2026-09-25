<?php

namespace Modules\DocumentAi\Providers;

use Illuminate\Support\ServiceProvider;

class DocumentAiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__, 2).'/lang', 'document_ai');
    }
}
