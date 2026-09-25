<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Mirrors the permission catalog and role templates into the database.
 * Safe to run again (the same as `php artisan access:sync` on deploy).
 */
class AccessSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('access:sync');
        $this->command?->info(trim(Artisan::output()));
    }
}
