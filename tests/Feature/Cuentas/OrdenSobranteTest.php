<?php

use App\Models\Categoria;
use App\Models\Direccion;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StatusCuentaSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(StatusCuentaSeeder::class);
});

test('el paso de sobrante muestra los productos en el orden de la hoja impresa', function () {
    $categoria = Categoria::factory()->create();

    foreach (['ZZ SIN LISTA', 'TENDER', '*PECHUGA', 'PAPA GAJO', 'AAA SIN LISTA'] as $nombre) {
        Producto::factory()->create(['name' => $nombre, 'categoria_id' => $categoria->id]);
    }

    $direccion = Direccion::create(['codigo_postal' => '09837', 'colonia' => 'San Miguel', 'estado' => 'CDMX', 'numero_interior' => 'S/N', 'numero_exterior' => 'S/N', 'calle' => 'Calle 1']);
    $sucursal = Sucursal::create(['name' => 'CARMEN SERDAN', 'direccion_id' => $direccion->id, 'activo' => true]);
    $capturista = User::factory()->create(['sucursal_id' => $sucursal->id]);
    $capturista->assignRole('Capturista');

    $componente = Livewire::actingAs($capturista)
        ->test('pages::cuentas.registrar')
        ->call('presentar')
        ->set('step', 6);

    $componente->assertSeeInOrder(['*PECHUGA', 'PAPA GAJO', 'TENDER', 'AAA SIN LISTA', 'ZZ SIN LISTA']);

    // El orden visual no altera los índices de los campos capturados.
    $indices = $componente->instance()->indicesSobranteOrdenados();
    $nombres = collect($indices)->map(fn ($i) => $componente->get('items')[$i]['producto'])->all();

    expect($nombres)->toBe(['*PECHUGA', 'PAPA GAJO', 'TENDER', 'AAA SIN LISTA', 'ZZ SIN LISTA']);
});

test('el paso de entradas usa el mismo orden de la hoja impresa que el de sobrante', function () {
    $categoria = Categoria::factory()->create();

    foreach (['ZZ SIN LISTA', 'TENDER', '*PECHUGA', 'PAPA GAJO', 'AAA SIN LISTA'] as $nombre) {
        Producto::factory()->create(['name' => $nombre, 'categoria_id' => $categoria->id]);
    }

    $direccion = Direccion::create(['codigo_postal' => '09837', 'colonia' => 'San Miguel', 'estado' => 'CDMX', 'numero_interior' => 'S/N', 'numero_exterior' => 'S/N', 'calle' => 'Calle 1']);
    $sucursal = Sucursal::create(['name' => 'CARMEN SERDAN', 'direccion_id' => $direccion->id, 'activo' => true]);
    $capturista = User::factory()->create(['sucursal_id' => $sucursal->id]);
    $capturista->assignRole('Capturista');

    $componente = Livewire::actingAs($capturista)
        ->test('pages::cuentas.registrar')
        ->call('presentar')
        ->set('step', 2);

    $componente->assertSeeInOrder(['*PECHUGA', 'PAPA GAJO', 'TENDER', 'AAA SIN LISTA', 'ZZ SIN LISTA']);
});
