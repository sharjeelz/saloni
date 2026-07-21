<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateSuperAdmin extends Command
{
    protected $signature = 'salon:super-admin
                            {email : Login email}
                            {--name=Super Admin : Display name}
                            {--password= : Password (prompted if omitted)}';

    protected $description = 'Create a platform super-admin (cross-tenant, no salon)';

    public function handle(): int
    {
        $email = $this->argument('email');

        if (User::where('email', $email)->exists()) {
            $this->error("A user with email {$email} already exists.");

            return self::FAILURE;
        }

        $password = $this->option('password') ?: $this->secret('Password');

        if (! $password || strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $user = User::create([
            'salon_id' => null,
            'name' => $this->option('name'),
            'email' => $email,
            'role' => 'super_admin',
            'is_active' => true,
            'password' => Hash::make($password),
        ]);

        $this->info("Super-admin created: {$user->email} (id {$user->id})");

        return self::SUCCESS;
    }
}
