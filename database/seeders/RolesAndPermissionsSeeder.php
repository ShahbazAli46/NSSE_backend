<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Create all Core Institutional Roles
        $roles = [
            'super_admin',     // System IT/Developers
            'admin',           // Administration
            'chairman',        // Macro campus monitoring
            'director',        // Executive monitoring & approvals
            'principal',       // Complete 360 operational control
            'accountant',      // Financial & Accounts Officer
            'hr_accountant',   // HR & Accounts
            'office_admin',    // Administration / Office Desk
            'teacher',         // Teaching Staff
            'support_staff',   // Non-Teaching Staff
            'parent',          // Parents
            'student'          // Students
        ];

        $roleInstances = [];
        foreach ($roles as $roleName) {
            $roleInstances[$roleName] = Role::firstOrCreate(['name' => $roleName]);
        }

        // 2. Create base permissions (we can add more module by module later)
        $permissions = [
            'view expenses',
            'create expenses',
            'edit expenses',
            'delete expenses',
            'approve expenses',
            'reject expenses',
            'manage roles',
            'manage users',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // 3. Assign some basic permissions as defaults (Will expand as we build modules)
        $roleInstances['super_admin']->syncPermissions(Permission::all());

        $roleInstances['hr_accountant']->syncPermissions([
            'view expenses',
            'create expenses',
            'edit expenses',
        ]);

        $roleInstances['director']->syncPermissions([
            'view expenses',
            'approve expenses',
            'reject expenses',
        ]);
        
        $roleInstances['principal']->syncPermissions([
            'view expenses',
            'approve expenses',
            'reject expenses',
        ]);
    }
}
