<?php

use App\Models\Cuenta;
use App\Models\Direccion;
use App\Models\Gasto;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StatusCuentaSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(StatusCuentaSeeder::class);
});

function crearCuentaParaDetalle(): array
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

    return [$admin, $cuenta, $sucursal];
}

test('el detalle muestra el resumen de la venta y los estados vacios de cada pestaña', function () {
    [$admin, $cuenta] = crearCuentaParaDetalle();

    Livewire::actingAs($admin)
        ->test('pages::cuentas.show', ['cuenta' => $cuenta])
        ->assertSee('ESCUADRON 201')
        ->assertSee('¿Cómo se calculó la venta?')
        ->assertSee('Cobro de la venta')
        ->assertSee('Cuenta cuadrada')
        ->assertSee('Todavía no hay productos')
        ->assertSee('Sin entradas de otras sucursales')
        ->assertSee('Sin salidas registradas')
        ->assertSee('Sin gastos registrados')
        ->assertSee('Sin mermas registradas');
});

test('el detalle lista los gastos y avisa cuando falta por cobrar', function () {
    [$admin, $cuenta, $sucursal] = crearCuentaParaDetalle();

    Gasto::create([
        'concepto' => 'GAS DEL DIA',
        'precio' => 150,
        'sucursal_id' => $sucursal->id,
        'cuenta_id' => $cuenta->id,
    ]);

    $componente = Livewire::actingAs($admin)
        ->test('pages::cuentas.show', ['cuenta' => $cuenta])
        ->assertSee('GAS DEL DIA')
        ->assertSee('$150.00');

    $cuenta->refresh();

    expect((float) $cuenta->total_venta)->toBe(-150.0);

    $componente->call('abrirGasto', Gasto::first()->id)
        ->assertSet('openGasto', true)
        ->assertSet('gastoForm.concepto', 'GAS DEL DIA');
});
