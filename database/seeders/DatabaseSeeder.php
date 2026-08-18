<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $this->call(UploadTypeSeeder::class);
        $this->call(PurchaseOrderItemScheduleSeeder::class);

        if (app()->environment('local', 'testing')
            || filled(config('receiving.initial_admin.email'))) {
            $this->call(UserSeeder::class);
        }
    }
}
