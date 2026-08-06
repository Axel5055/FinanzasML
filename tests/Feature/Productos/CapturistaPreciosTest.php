<?php

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('capturista can view the products page on the prices tab', function () {
    $capturista = User::factory()->create();
    $capturista->assignRole('Capturista');

    $response = $this->actingAs($capturista)->get(route('admin.productos.index'));

    $response->assertOk();

    Livewire::test('pages::productos.index')
        ->assertSet('canManageCatalogo', false)
        ->assertSet('tab', 'precios');
});

test('capturista can update product prices', function () {
    $capturista = User::factory()->create();
    $capturista->assignRole('Capturista');

    $producto = Producto::factory()->create(['precio' => 10]);

    $this->actingAs($capturista);

    Livewire::test('pages::productos.index')
        ->set('precios.0.precio', 99.5)
        ->call('guardarPrecios')
        ->assertHasNoErrors();

    expect((float) $producto->refresh()->precio)->toBe(99.5);
});

test('capturista cannot create, edit, or delete products', function () {
    $capturista = User::factory()->create();
    $capturista->assignRole('Capturista');

    $categoria = Categoria::factory()->create();
    $producto = Producto::factory()->create(['categoria_id' => $categoria->id]);

    $this->actingAs($capturista);

    Livewire::test('pages::productos.index')
        ->set('form.name', 'Producto nuevo')
        ->set('form.categoria_id', $categoria->id)
        ->call('crear')
        ->assertStatus(403);

    Livewire::test('pages::productos.index')
        ->call('abrirEditar', $producto->id)
        ->assertStatus(403);

    Livewire::test('pages::productos.index')
        ->call('eliminar', $producto->id)
        ->assertStatus(403);

    $this->assertDatabaseHas('productos', ['id' => $producto->id]);
});

test('admin can still manage the full product catalog', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $categoria = Categoria::factory()->create();

    $this->actingAs($admin);

    Livewire::test('pages::productos.index')
        ->assertSet('canManageCatalogo', true)
        ->assertSet('tab', 'productos')
        ->set('form.name', 'Producto admin')
        ->set('form.categoria_id', $categoria->id)
        ->call('crear')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('productos', ['name' => 'PRODUCTO ADMIN']);
});
