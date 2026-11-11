<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * The roles/permissions matrix is seeded everywhere. The test account and the demo
     * family are for local development only and are never created in staging or production.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        if (app()->environment('local')) {
            User::query()->firstOrCreate(
                ['email' => 'test@example.com'],
                ['name' => 'Test User', 'password' => 'password-for-local-dev'],
            )->forceFill(['email_verified_at' => now()])->save();

            $this->call(DemoFamilySeeder::class);
        }
    }
}
