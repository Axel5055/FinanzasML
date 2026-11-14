<?php

use App\Actions\Permisos\SeccionesDelAdmin;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('el menu agrupa registrar cuenta como subseccion de cuentas', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Todas las cuentas')
        ->assertSee('Registrar cuenta')
        ->assertSee(route('admin.cuentas.index'), false)
        ->assertSee(route('admin.registrar.index'), false);
});

test('si el admin no tiene cuentas pero si registrar, registrar aparece suelto en el menu', function () {
    app(SeccionesDelAdmin::class)->cambiar('cuentas', false);

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Todas las cuentas')
        ->assertSee(route('admin.registrar.index'), false);
});

test('el menu lateral muestra la version del sistema y los creditos', function () {
    config(['app.version' => '2.5.1']);

    $usuario = User::factory()->create();
    $usuario->assignRole('Admin');

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Versión 2.5.1')
        ->assertSee('Todos los derechos reservados')
        ->assertSee('Mycleth');
});
