<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Role::create(['name' => 'Admin']);
        Role::create(['name' => 'Capturista']);

        Permission::create(['name' => 'admin.cuentas.index'])->assignRole($admin);
        Permission::create(['name' => 'admin.cuentas.show'])->assignRole($admin);
        Permission::create(['name' => 'admin.registrar.index'])->assignRole($admin);
        Permission::create(['name' => 'admin.sucursales.index'])->assignRole($admin);
        Permission::create(['name' => 'admin.productos.index'])->assignRole($admin);
        Permission::create(['name' => 'admin.users.index'])->assignRole($admin);
    }
}
