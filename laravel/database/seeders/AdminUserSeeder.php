<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // WARNING: Change these credentials for any non-dev environment.
        $email = 'admin@example.com';
        $password = 'password';

        // Ensure admin role exists
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin',
                'username' => 'admin',
                'password' => Hash::make($password),
            ]
        );

        if ($user->username !== 'admin') {
            $user->forceFill(['username' => 'admin'])->save();
        }

        if (!$user->hasRole($role)) {
            $user->assignRole($role);
        }
    }
}
