<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Define permissions
        $viewDashboard = Permission::firstOrCreate(['name' => 'view dashboard', 'guard_name' => 'web']);
        $accessFilament = Permission::firstOrCreate(['name' => 'access filament', 'guard_name' => 'web']);
        $createOrders = Permission::firstOrCreate(['name' => 'create orders', 'guard_name' => 'web']);

        // Define roles
        $viewer = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        // Assign permissions
        if (! $viewer->hasPermissionTo($viewDashboard)) {
            $viewer->givePermissionTo($viewDashboard);
        }

        $admin->syncPermissions([$viewDashboard, $accessFilament, $createOrders]);
    }
}
