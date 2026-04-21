<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Admin login — matches the user created during the Phase 1 scaffold.
        // updateOrCreate so re-seeding doesn't blow up on the unique email.
        User::updateOrCreate(
            ['email' => 'admin@sitesatscale.com'],
            [
                'name'              => 'Admin',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ],
        );

        $this->call([
            SiteSeeder::class,
        ]);
    }
}
