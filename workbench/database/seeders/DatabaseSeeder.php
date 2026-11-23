<?php

namespace Database\Seeders;

use Hata\Database\Seeders\DatabaseSeeder as HataSeeder;
use Illuminate\Database\Seeder;

/**
 * The test host's main seeder: Hata's seeding, as a site calls it.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(HataSeeder::class);
    }
}
