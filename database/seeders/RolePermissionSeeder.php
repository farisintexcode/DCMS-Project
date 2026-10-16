<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Plan.md §4 / Module.md M01/M12; reviewable baseline, no user accounts or production grants. */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (config('dcms.permissions') as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $rider = Role::findOrCreate('Rider', 'web');
        if ($rider->wasRecentlyCreated) {
            $rider->syncPermissions(config('dcms.rider_permissions'));
        }
        $admin = Role::findOrCreate('Admin', 'web');
        if ($admin->wasRecentlyCreated) {
            $admin->syncPermissions(array_values(array_diff(config('dcms.permissions'), config('dcms.rider_only_permissions'), config('dcms.reserved_permissions'))));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
