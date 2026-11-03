<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Two development accounts, to check that one user cannot open another user's videos.
     * Their password is the UserFactory default. Running the seeder again changes nothing.
     */
    public function run(): void
    {
        foreach (['test@example.com' => 'Test User', 'second@example.com' => 'Second User'] as $email => $name) {
            if (! User::where('email', $email)->exists()) {
                User::factory()->create(['name' => $name, 'email' => $email]);
            }
        }
    }
}
