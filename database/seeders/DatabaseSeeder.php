<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('Demo data is only available in the local environment.');

            return;
        }
        $this->call(DemoSeeder::class);
    }
}
