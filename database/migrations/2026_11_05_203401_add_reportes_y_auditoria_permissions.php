<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Reportes y Auditoría pasan a controlarse por permisos para que el
     * Super Admin pueda habilitarlos o quitarlos al rol Admin.
     *
     * Se conserva el acceso actual a Reportes y se deja Auditoría solo
     * para el Super Admin.
     */
    public function up(): void
    {
        $reportes = Permission::findOrCreate('admin.reportes.index', 'web');
        $auditoria = Permission::findOrCreate('admin.auditoria.index', 'web');

        Role::where('name', 'Admin')->first()?->givePermissionTo($reportes);

        Role::where('name', 'Super Admin')->first()?->givePermissionTo($reportes, $auditoria);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', ['admin.reportes.index', 'admin.auditoria.index'])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
