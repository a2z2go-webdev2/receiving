<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $name = trim((string) config('receiving.initial_admin.name'));
        $email = trim((string) config('receiving.initial_admin.email'));
        $password = (string) config('receiving.initial_admin.password');

        if (app()->environment('testing')) {
            $email = $email !== '' ? $email : 'admin@example.com';
            $password = $password !== '' ? $password : 'password';
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Set a valid INITIAL_ADMIN_EMAIL before running the user seeder.');
        }

        if (! app()->environment('testing') && strlen($password) < 12) {
            throw new RuntimeException('INITIAL_ADMIN_PASSWORD must contain at least 12 characters.');
        }

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name !== '' ? $name : 'Receiving Administrator',
                'password' => $password,
                'status' => UserStatus::Active,
            ],
        );
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
        $user->syncRoles(['admin']);
    }
}
