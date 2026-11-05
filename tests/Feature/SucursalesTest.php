<?php

use App\Models\Cuenta;
use App\Models\Direccion;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StatusCuentaSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(StatusCuentaSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Admin');
});

function crearSucursalDePrueba(string $nombre, bool $activo = true, string $calle = 'Calle 1'): Sucursal
{
    $direccion = Direccion::create([
        'codigo_postal' => '09837',
        'colonia' => 'San Miguel',
        'estado' => 'CDMX',
        'numero_interior' => 'S/N',
        'numero_exterior' => 'S/N',
        'calle' => $calle,
    ]);

    return Sucursal::create(['name' => $nombre, 'direccion_id' => $direccion->id, 'activo' => $activo]);
}

test('la vista de sucursales muestra tarjetas con su estado y direccion', function () {
    crearSucursalDePrueba('CARMEN SERDAN', true, 'Av. Reforma');
    crearSucursalDePrueba('BODEGA', false);

    $this->actingAs($this->admin)
        ->get(route('admin.sucursales.index'))
        ->assertOk()
        ->assertSee('CARMEN SERDAN')
        ->assertSee('Av. Reforma')
        ->assertSee('Activa')
        ->assertSee('Inactiva');
});

test('el filtro de estado y la busqueda acotan las sucursales visibles', function () {
    crearSucursalDePrueba('CARMEN SERDAN', true, 'Av. Reforma');
    crearSucursalDePrueba('EL VERDE', true, 'Calle Verde');
    crearSucursalDePrueba('BODEGA', false);

    $component = Livewire::actingAs($this->admin)->test('pages::sucursales.index');

    expect($component->get('visibles')->pluck('name')->all())->toBe(['BODEGA', 'CARMEN SERDAN', 'EL VERDE']);

    $component->set('filtro', 'activas');
    expect($component->get('visibles')->pluck('name')->all())->toBe(['CARMEN SERDAN', 'EL VERDE']);

    $component->set('filtro', 'inactivas');
    expect($component->get('visibles')->pluck('name')->all())->toBe(['BODEGA']);

    $component->set('filtro', 'todas')->set('buscar', 'reforma');
    expect($component->get('visibles')->pluck('name')->all())->toBe(['CARMEN SERDAN']);
});

test('cada sucursal muestra sus ventas y cuentas del mes en curso', function () {
    $sucursal = crearSucursalDePrueba('CARMEN SERDAN');

    Cuenta::unguarded(function () use ($sucursal) {
        foreach ([1000, 2500.5] as $total) {
            Cuenta::create([
                'efectivo_pollo' => 0,
                'efectivo_marinado' => 0,
                'efectivo_total' => 0,
                'efectivo_entregado' => 0,
                'total_venta' => $total,
                'tarjeta' => 0,
                'diferencia' => 0,
                'sobrante' => 0,
                'fecha_captura' => now()->toDateString(),
                'fecha_venta' => now()->toDateString(),
                'sucursal_id' => $sucursal->id,
                'status_cuenta_id' => 1,
            ]);
        }
    });

    $component = Livewire::actingAs($this->admin)->test('pages::sucursales.index');

    $resumen = $component->get('resumenMes')[$sucursal->id];

    expect((float) $resumen->ventas)->toBe(3500.5)
        ->and((int) $resumen->cuentas)->toBe(2);

    $component->assertSee('$3,500.50');
});

test('la vista predeterminada son las tarjetas y el boton alterna con la lista', function () {
    crearSucursalDePrueba('CARMEN SERDAN', true, 'Av. Reforma');

    Livewire::actingAs($this->admin)
        ->test('pages::sucursales.index')
        ->assertSet('vista', 'tarjetas')
        ->assertDontSee('Ventas del mes</th>', false)
        ->assertSee('Cuentas del mes')
        ->call('cambiarVista')
        ->assertSet('vista', 'lista')
        ->assertSee('<th class="px-3 py-3">Dirección</th>', false)
        ->assertSee('CARMEN SERDAN')
        ->assertSee('Av. Reforma')
        ->call('cambiarVista')
        ->assertSet('vista', 'tarjetas')
        ->assertSee('Cuentas del mes');
});

test('la vista en lista respeta la busqueda y el filtro de estado', function () {
    crearSucursalDePrueba('CARMEN SERDAN');
    crearSucursalDePrueba('BODEGA', false);

    Livewire::actingAs($this->admin)
        ->test('pages::sucursales.index')
        ->call('cambiarVista')
        ->set('filtro', 'activas')
        ->assertSee('CARMEN SERDAN')
        ->assertDontSee('BODEGA');
});
