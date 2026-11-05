<?php

namespace App\Actions\Permisos;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SeccionesDelAdmin
{
    /**
     * Secciones del sistema cuyo acceso el Super Admin puede otorgar o quitar al rol Admin.
     *
     * @var array<string, array{etiqueta: string, descripcion: string, icono: string, permisos: list<string>}>
     */
    public const SECCIONES = [
        'cuentas' => [
            'etiqueta' => 'Cuentas',
            'descripcion' => 'Lista de cuentas y su detalle, con PDF.',
            'icono' => 'banknotes',
            'permisos' => ['admin.cuentas.index', 'admin.cuentas.show'],
        ],
        'registrar' => [
            'etiqueta' => 'Registrar cuenta',
            'descripcion' => 'Captura y edición de cuentas diarias.',
            'icono' => 'clipboard-document-list',
            'permisos' => ['admin.registrar.index'],
        ],
        'sucursales' => [
            'etiqueta' => 'Sucursales',
            'descripcion' => 'Alta, edición y estado de las sucursales.',
            'icono' => 'building-storefront',
            'permisos' => ['admin.sucursales.index'],
        ],
        'productos' => [
            'etiqueta' => 'Productos',
            'descripcion' => 'Catálogo de productos y precios maestros.',
            'icono' => 'cube',
            'permisos' => ['admin.productos.index'],
        ],
        'usuarios' => [
            'etiqueta' => 'Usuarios',
            'descripcion' => 'Administradores y capturistas del sistema.',
            'icono' => 'users',
            'permisos' => ['admin.users.index'],
        ],
        'reportes' => [
            'etiqueta' => 'Reportes',
            'descripcion' => 'Reportes por periodo y sucursal en PDF o CSV.',
            'icono' => 'document-chart-bar',
            'permisos' => ['admin.reportes.index'],
        ],
        'auditoria' => [
            'etiqueta' => 'Auditoría',
            'descripcion' => 'Historial de movimientos: quién hizo qué y cuándo.',
            'icono' => 'clipboard-document-check',
            'permisos' => ['admin.auditoria.index'],
        ],
    ];

    /**
     * @return array<string, bool>
     */
    public function estado(): array
    {
        $permisosDelAdmin = $this->rolAdmin()->permissions->pluck('name');

        return collect(self::SECCIONES)
            ->map(fn (array $seccion) => collect($seccion['permisos'])->every(fn (string $permiso) => $permisosDelAdmin->contains($permiso)))
            ->all();
    }

    public function cambiar(string $seccion, bool $habilitada): void
    {
        abort_unless(array_key_exists($seccion, self::SECCIONES), 422);

        $rol = $this->rolAdmin();

        foreach (self::SECCIONES[$seccion]['permisos'] as $nombre) {
            $permiso = Permission::findOrCreate($nombre, 'web');

            $habilitada ? $rol->givePermissionTo($permiso) : $rol->revokePermissionTo($permiso);
        }
    }

    private function rolAdmin(): Role
    {
        return Role::findOrCreate('Admin', 'web')->load('permissions');
    }
}
