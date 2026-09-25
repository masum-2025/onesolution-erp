<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Model events stay on: ULIDs, tree guards and audit entries rely on them.
     */
    public function run(): void
    {
        $this->call(HousePartnerSeeder::class);

        if (app()->environment('local')) {
            $this->call(DemoHierarchySeeder::class);
        }
    }
}
