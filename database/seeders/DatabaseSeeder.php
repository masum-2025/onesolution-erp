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
        $this->call([HousePartnerSeeder::class, RulesSeeder::class, CountriesSeeder::class, AccessSeeder::class, PackagingSeeder::class, BillingSeeder::class, LegalSeeder::class]);

        if (app()->environment('local')) {
            $this->call([DemoHierarchySeeder::class, DemoModulesSeeder::class, DemoAccessSeeder::class, DemoPartnerSeeder::class, DemoIdentitySeeder::class, DemoStockSeeder::class]);
        }
    }
}
