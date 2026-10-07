<?php

namespace Database\Seeders;

use Database\Seeders\Access\AccessControlSeeder;
use Database\Seeders\Accounting\AccountingChartSeeder;
use Database\Seeders\Accounting\AccountingFoundationSeeder;
use Database\Seeders\Accounting\AccountingPeriodSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            AccountingFoundationSeeder::class,
            AccountingChartSeeder::class,
            AccountingPeriodSeeder::class,
            AccessControlSeeder::class,
        ]);
    }
}
