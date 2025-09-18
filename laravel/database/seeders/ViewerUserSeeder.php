<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class ViewerUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'viewer@example.com';
        $password = 'password';

        $role = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Viewer',
                'password' => Hash::make($password),
            ]
        );

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
    }
}
