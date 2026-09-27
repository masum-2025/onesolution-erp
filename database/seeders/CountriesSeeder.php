<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Countries from database/data/countries, with their rule defaults (Phase 6).
 * Safe to run again.
 */
class CountriesSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->call('countries:sync');
    }
}
