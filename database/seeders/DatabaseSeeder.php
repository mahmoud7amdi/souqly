<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CurrenciesTableSeeder::class,
            PermissionsTableSeeder::class,
            // Must run last: it provisions a tenant, which needs the
            // permission rows to already exist.
            AdminUserSeeder::class,
        ]);
    }
}
