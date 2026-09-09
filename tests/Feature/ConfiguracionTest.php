<?php

use App\Models\Configuracion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('el seeder de roles crea Super Admin con todos los permisos de Admin mas el de configuracion', function () {
    $admin = Role::findByName('Admin');
    $superAdmin = Role::findByName('Super Admin');

    expect($superAdmin)->not->toBeNull();

    $permisosAdmin = $admin->permissions->pluck('name');
    $permisosSuperAdmin = $superAdmin->permissions->pluck('name');

    foreach ($permisosAdmin as $permiso) {
        expect($permisosSuperAdmin)->toContain($permiso);
    }

    expect($permisosSuperAdmin)->toContain('admin.configuracion.index');
});

test('super admin puede ver y cambiar la configuracion de IA', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    $response = $this->actingAs($superAdmin)->get(route('admin.configuracion.index'));
    $response->assertOk();

    Livewire::actingAs($superAdmin)
        ->test('pages::configuracion.index')
        ->assertSet('iaCapturaHabilitada', true)
        ->set('iaCapturaHabilitada', false);

    expect(Configuracion::activa(Configuracion::IA_CAPTURA_HABILITADA))->toBeFalse();
});

test('un admin normal no puede acceder a la configuracion', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)
        ->get(route('admin.configuracion.index'))
        ->assertForbidden();
});
