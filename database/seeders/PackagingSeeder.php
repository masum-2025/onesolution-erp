<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Mirrors plans, plan prices and sector packages into the database.
 * Safe to run again (the same as `php artisan packaging:sync` on deploy).
 */
class PackagingSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('packaging:sync');
        $this->command?->info(trim(Artisan::output()));
    }
}
