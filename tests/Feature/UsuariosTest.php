<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('un admin no ve la opcion de rol Super Admin al listar roles disponibles', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $component = Livewire::actingAs($admin)->test('pages::usuarios.index');

    expect($component->get('roles')->pluck('name'))->not->toContain('Super Admin');
});

test('un super admin si ve la opcion de rol Super Admin al listar roles disponibles', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    $component = Livewire::actingAs($superAdmin)->test('pages::usuarios.index');

    expect($component->get('roles')->pluck('name'))->toContain('Super Admin');
});

test('un admin no puede crear un usuario con rol Super Admin aunque lo envie directo', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    Livewire::actingAs($admin)
        ->test('pages::usuarios.index')
        ->set('form.name', 'Intento Malicioso')
        ->set('form.email', 'intento@mlgrupo.com.mx')
        ->set('form.rol', 'Super Admin')
        ->set('form.password', 'password123')
        ->set('form.password_confirmation', 'password123')
        ->call('crear')
        ->assertHasErrors(['form.rol']);

    expect(User::where('email', 'intento@mlgrupo.com.mx')->exists())->toBeFalse();
});

test('un super admin si puede crear un usuario con rol Super Admin', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    Livewire::actingAs($superAdmin)
        ->test('pages::usuarios.index')
        ->set('form.name', 'Nuevo Super Admin')
        ->set('form.email', 'nuevo.super@mlgrupo.com.mx')
        ->set('form.rol', 'Super Admin')
        ->set('form.password', 'password123')
        ->set('form.password_confirmation', 'password123')
        ->call('crear')
        ->assertHasNoErrors();

    $nuevo = User::where('email', 'nuevo.super@mlgrupo.com.mx')->first();
    expect($nuevo)->not->toBeNull()
        ->and($nuevo->hasRole('Super Admin'))->toBeTrue();
});

test('la lista de usuarios muestra nombre, correo, rol y estado', function () {
    $admin = User::factory()->create(['name' => 'Ana Admin', 'email' => 'ana@mlgrupo.com.mx']);
    $admin->assignRole('Admin');

    $capturista = User::factory()->create(['name' => 'Beto Capturista', 'activo' => false]);
    $capturista->assignRole('Capturista');

    Livewire::actingAs($admin)
        ->test('pages::usuarios.index')
        ->assertSee('Ana Admin')
        ->assertSee('ana@mlgrupo.com.mx')
        ->assertSee('Capturista')
        ->assertSee('Activo')
        ->assertSee('Deshabilitado');
});

test('la busqueda y los filtros de rol y estado acotan los usuarios visibles', function () {
    $admin = User::factory()->create(['name' => 'Ana Admin']);
    $admin->assignRole('Admin');

    $activo = User::factory()->create(['name' => 'Carla Capturista']);
    $activo->assignRole('Capturista');

    $inactivo = User::factory()->create(['name' => 'Beto Capturista', 'activo' => false]);
    $inactivo->assignRole('Capturista');

    $component = Livewire::actingAs($admin)->test('pages::usuarios.index');

    expect($component->get('visibles'))->toHaveCount(3);

    $component->set('rolFiltro', 'Capturista');
    expect($component->get('visibles')->pluck('name')->all())->toBe(['Beto Capturista', 'Carla Capturista']);

    $component->set('filtro', 'deshabilitados');
    expect($component->get('visibles')->pluck('name')->all())->toBe(['Beto Capturista']);

    $component->set('filtro', 'activos')->set('rolFiltro', '')->set('buscar', 'carla');
    expect($component->get('visibles')->pluck('name')->all())->toBe(['Carla Capturista']);

    $component->call('limpiarFiltros')
        ->assertSet('buscar', '')
        ->assertSet('rolFiltro', '')
        ->assertSet('filtro', 'todos');
    expect($component->get('visibles'))->toHaveCount(3);
});

test('el resumen cuenta usuarios, activos y administradores', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $capturista = User::factory()->create(['activo' => false]);
    $capturista->assignRole('Capturista');

    Livewire::actingAs($admin)
        ->test('pages::usuarios.index')
        ->assertSeeInOrder(['Usuarios', '2', 'Activos', '1', 'Administradores', '1']);
});

test('en pantallas chicas la fila de usuario resume rol, estado y sucursal bajo el nombre', function () {
    $admin = User::factory()->create(['name' => 'Ana Admin', 'sucursal_id' => null]);
    $admin->assignRole('Admin');

    Livewire::actingAs($admin)
        ->test('pages::usuarios.index')
        ->assertSeeHtml('md:hidden')
        ->assertSeeHtml('hidden px-3 py-3 md:table-cell')
        ->assertSee('Sin sucursal');
});
