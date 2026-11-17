<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * The admin and demo users everywhere; fake generations only outside production
     * (FR-022). In production, demo generations go through the real API instead
     * (pixelcat:demo-generations), so every recorded cost matches Pixelforge's records.
     */
    public function run(): void
    {
        $this->call(UsersSeeder::class);

        if (! app()->isProduction()) {
            $this->call(DemoGenerationsSeeder::class);
        }
    }
}
