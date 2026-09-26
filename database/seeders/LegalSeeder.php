<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Publishes the platform's default legal documents (the same as
 * `php artisan legal:sync` on deploy). Safe to run again.
 */
class LegalSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('legal:sync');
        $this->command?->info(trim(Artisan::output()));
    }
}
