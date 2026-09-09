<?php

use App\Models\Categoria;
use App\Models\Cuenta;
use App\Models\Direccion;
use App\Models\ItemCuenta;
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

function crearSucursalConCapturista(): array
{
    $direccion = Direccion::create([
        'codigo_postal' => '09837',
        'colonia' => 'San Miguel',
        'estado' => 'CDMX',
        'numero_interior' => 'S/N',
        'numero_exterior' => 'S/N',
        'calle' => 'Calle 1',
    ]);
    $sucursal = Sucursal::create(['name' => 'ESCUADRON 201', 'direccion_id' => $direccion->id, 'activo' => true]);

    $capturista = User::factory()->create(['sucursal_id' => $sucursal->id]);
    $capturista->assignRole('Capturista');

    return [$sucursal, $capturista];
}

test('guardar la cuenta calcula la diferencia usando efectivo mas tarjeta', function () {
    [$sucursal, $capturista] = crearSucursalConCapturista();

    Livewire::actingAs($capturista)
        ->test('pages::cuentas.registrar')
        ->call('presentar')
        ->set('efectivoEntregado', 100)
        ->set('tarjeta', 50)
        ->assertSet('totalCapturado', 150.0)
        ->call('guardar');

    $cuenta = Cuenta::where('sucursal_id', $sucursal->id)->firstOrFail();

    expect((float) $cuenta->efectivo_entregado)->toBe(100.0)
        ->and((float) $cuenta->tarjeta)->toBe(50.0)
        ->and((float) $cuenta->diferencia)->toBe($cuenta->total_venta - 150.0);
});

test('actualizar efectivo y tarjeta en el detalle recalcula la diferencia', function () {
    [$sucursal] = crearSucursalConCapturista();

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $cuenta = Cuenta::create([
        'sucursal_id' => $sucursal->id,
        'fecha_venta' => now()->toDateString(),
        'fecha_captura' => now()->toDateString(),
        'efectivo_pollo' => 0,
        'efectivo_marinado' => 0,
        'efectivo_entregado' => 0,
        'tarjeta' => 0,
        'efectivo_total' => 0,
        'diferencia' => 0,
        'sobrante' => 0,
        'total_venta' => 0,
        'status_cuenta_id' => 1,
    ]);

    // mount() en la página de detalle recalcula total_venta a partir de las
    // tablas relacionadas, así que fijamos una entrada real de $1000 para
    // que el total quede en un valor determinista y no en 0.
    $producto = Producto::factory()->create(['categoria_id' => Categoria::factory()->create()->id]);
    ItemCuenta::create([
        'producto_id' => $producto->id,
        'cuenta_id' => $cuenta->id,
        'precio' => 10,
        'cantidad_existencia' => 0,
        'importe_existencia' => 0,
        'cantidad_entrada' => 100,
        'importe_entrada' => 1000,
        'cantidad_salida' => 0,
        'importe_salida' => 0,
        'cantidad_sobrante' => 0,
        'importe_sobrante' => 0,
        'cantidad_mayoreo' => 0,
        'importe_mayoreo' => 0,
        'fecha_venta' => $cuenta->fecha_venta,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::cuentas.show', ['cuenta' => $cuenta])
        ->call('abrirEfectivo')
        ->set('efectivoEntregado', 600)
        ->set('tarjeta', 300)
        ->call('guardarEfectivo');

    $cuenta->refresh();

    expect((float) $cuenta->total_venta)->toBe(1000.0)
        ->and((float) $cuenta->efectivo_entregado)->toBe(600.0)
        ->and((float) $cuenta->tarjeta)->toBe(300.0)
        ->and((float) $cuenta->diferencia)->toBe(100.0);
});
