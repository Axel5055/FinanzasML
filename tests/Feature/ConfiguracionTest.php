<?php

use App\Models\ActivityLog;
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

test('la configuracion muestra el estado actual del llenado con IA', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    Livewire::actingAs($superAdmin)
        ->test('pages::configuracion.index')
        ->assertSee('Habilitado')
        ->assertDontSee('Deshabilitado')
        ->set('iaCapturaHabilitada', false)
        ->assertSee('Deshabilitado');
});

test('por defecto el admin entra a reportes pero no a la auditoria y el menu lo refleja', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('admin.reportes.index'), false)
        ->assertDontSee(route('admin.auditoria.index'), false);

    $this->actingAs($admin)->get(route('admin.reportes.index'))->assertOk();
    $this->actingAs($admin)->get(route('admin.auditoria.index'))->assertForbidden();
});

test('el super admin ve y cambia los accesos del rol admin', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    Livewire::actingAs($superAdmin)
        ->test('pages::configuracion.index')
        ->assertSee('Accesos del rol Admin')
        ->assertSet('seccionesAdmin.auditoria', false)
        ->assertSet('seccionesAdmin.reportes', true)
        ->set('seccionesAdmin.auditoria', true)
        ->set('seccionesAdmin.reportes', false);

    $permisos = Role::findByName('Admin')->fresh()->permissions->pluck('name');

    expect($permisos)->toContain('admin.auditoria.index')
        ->and($permisos)->not->toContain('admin.reportes.index');

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)->get(route('admin.auditoria.index'))->assertOk();
    $this->actingAs($admin)->get(route('admin.reportes.index'))->assertForbidden();
});

test('quitar cuentas al admin deshabilita la lista y el detalle y lo deja en la bitacora', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    Livewire::actingAs($superAdmin)
        ->test('pages::configuracion.index')
        ->set('seccionesAdmin.cuentas', false);

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)->get(route('admin.cuentas.index'))->assertForbidden();

    expect(ActivityLog::where('description', 'like', '%Cuentas%rol Admin%')->exists())->toBeTrue();
});

test('el super admin conserva el acceso a todo aunque se quite al admin', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    Livewire::actingAs($superAdmin)
        ->test('pages::configuracion.index')
        ->set('seccionesAdmin.usuarios', false);

    $this->actingAs($superAdmin)->get(route('admin.users.index'))->assertOk();
    $this->actingAs($superAdmin)->get(route('admin.auditoria.index'))->assertOk();
});

test('un admin no puede cambiar los accesos de su propio rol', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)->get(route('admin.configuracion.index'))->assertForbidden();

    Livewire::actingAs($admin)
        ->test('pages::configuracion.index')
        ->set('seccionesAdmin.auditoria', true)
        ->assertForbidden();

    expect(Role::findByName('Admin')->permissions->pluck('name'))->not->toContain('admin.auditoria.index');
});
