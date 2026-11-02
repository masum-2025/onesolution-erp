<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Records the default wholesale and add-on prices (the same as `php artisan
 * billing:sync-prices` and `addons:sync-prices` on deploy). Safe to run again.
 */
class BillingSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('billing:sync-prices');
        $this->command?->info(trim(Artisan::output()));
        Artisan::call('addons:sync-prices');
        $this->command?->info(trim(Artisan::output()));
    }
}
