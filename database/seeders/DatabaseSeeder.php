<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * The roles/permissions matrix is seeded everywhere. The test account is for local
     * development only and is never created in staging or production.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        if (app()->environment('local')) {
            User::query()->firstOrCreate(
                ['email' => 'test@example.com'],
                ['name' => 'Test User', 'password' => 'password-for-local-dev'],
            )->forceFill(['email_verified_at' => now()])->save();
        }
    }
}
