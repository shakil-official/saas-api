<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'manage-users', 'manage-customers', 'manage-subscription', 'view-dashboard',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'api']);
        $admin->syncPermissions($permissions);

        $manager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'api']);
        $manager->syncPermissions(['manage-customers', 'view-dashboard']);

        $staff = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'api']);
        $staff->syncPermissions(['view-dashboard']);
    }
}
