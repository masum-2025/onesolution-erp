<?php

namespace Modules\Inventory\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Inventory\Export\InventoryExporter;

/**
 * Inventory: items, warehouses, the stock ledger (receipts, issues,
 * transfers, adjustments, counts) and its public Stock service for other
 * modules (POS). Posted through Accounting's Ledger where the company keeps
 * books.
 */
class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The client's data export includes inventory (ExportsModuleData).
        $this->app->tag([InventoryExporter::class], 'module.exporters');
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loadTranslationsFrom($root.'/lang', 'inventory');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group($root.'/routes/api.php');
        }
    }
}
