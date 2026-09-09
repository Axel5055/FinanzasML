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
        $superAdmin = Role::create(['name' => 'Super Admin']);

        Permission::create(['name' => 'admin.cuentas.index'])->assignRole($admin);
        Permission::create(['name' => 'admin.cuentas.show'])->assignRole($admin);
        Permission::create(['name' => 'admin.registrar.index'])->assignRole($admin);
        Permission::create(['name' => 'admin.sucursales.index'])->assignRole($admin);
        Permission::create(['name' => 'admin.productos.index'])->assignRole($admin);
        Permission::create(['name' => 'admin.users.index'])->assignRole($admin);
        Permission::create(['name' => 'admin.configuracion.index'])->assignRole($superAdmin);

        // Super Admin tiene todo lo de Admin, más los permisos exclusivos de arriba.
        $superAdmin->syncPermissions(Permission::all());
    }
}
