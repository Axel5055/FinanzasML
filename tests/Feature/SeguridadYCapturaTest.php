<?php

use App\Actions\Fechas\DiaDeNegocio;
use App\Models\Categoria;
use App\Models\Cuenta;
use App\Models\Direccion;
use App\Models\Gasto;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StatusCuentaSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(StatusCuentaSeeder::class);

    $categoria = Categoria::factory()->create();
    $this->producto = Producto::factory()->create(['name' => 'PECHUGA', 'categoria_id' => $categoria->id]);

    $direccion = Direccion::create(['codigo_postal' => '1', 'colonia' => 'c', 'estado' => 'e', 'numero_interior' => 's', 'numero_exterior' => 's', 'calle' => 'c']);
    $this->sucursalA = Sucursal::create(['name' => 'A', 'direccion_id' => $direccion->id, 'activo' => true]);
    $this->sucursalB = Sucursal::create(['name' => 'B', 'direccion_id' => $direccion->id, 'activo' => true]);

    $this->capturista = User::factory()->create(['sucursal_id' => $this->sucursalA->id]);
    $this->capturista->assignRole('Capturista');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Admin');
});

function cuentaVacia(Sucursal $sucursal, string $fecha, array $extra = []): Cuenta
{
    return Cuenta::create(array_merge([
        'sucursal_id' => $sucursal->id, 'fecha_venta' => $fecha, 'fecha_captura' => $fecha,
        'efectivo_entregado' => 0, 'tarjeta' => 0, 'total_venta' => 0, 'diferencia' => 0, 'sobrante' => 0,
        'efectivo_pollo' => 0, 'efectivo_marinado' => 0, 'efectivo_total' => 0,
    ], $extra));
}

test('un capturista no puede cambiar la sucursal desde el navegador', function () {
    Livewire::actingAs($this->capturista)->test('pages::cuentas.registrar')
        ->call('presentar')
        ->set('sucursalId', $this->sucursalB->id)
        ->assertStatus(403);

    expect(Cuenta::where('sucursal_id', $this->sucursalB->id)->exists())->toBeFalse();
});

test('las propiedades de autoridad de registrar estan bloqueadas', function (string $propiedad, mixed $valor) {
    Livewire::actingAs($this->capturista)->test('pages::cuentas.registrar')
        ->set($propiedad, $valor);
})->with([
    ['esAdmin', true],
    ['cuentaEditandoId', 1],
    ['sumExistencia', 999999],
    ['sumSobrante', 1],
    ['totalSalidas', 1],
])->throws(Exception::class, 'Cannot update locked property');

test('guardar recalcula los importes en el servidor y no confia en el total del navegador', function () {
    $componente = Livewire::actingAs($this->capturista)->test('pages::cuentas.registrar')->call('presentar');

    $items = $componente->get('items');
    $items[0]['precio'] = 10;
    $items[0]['cantidad_entrada'] = 5;
    $items[0]['importe_entrada'] = 999999; // importe falsificado

    $componente->set('items', $items)->call('guardar');

    $cuenta = Cuenta::where('sucursal_id', $this->sucursalA->id)->firstOrFail();

    expect((float) $cuenta->total_venta)->toBe(50.0);
});

test('un capturista no puede guardar dos cuentas el mismo dia aunque se salte presentar', function () {
    Livewire::actingAs($this->capturista)->test('pages::cuentas.registrar')->call('presentar')->call('guardar');

    Livewire::actingAs($this->capturista)->test('pages::cuentas.registrar')
        ->set('buttonsDisabled', false)
        ->set('items', [['producto_id' => $this->producto->id, 'producto' => 'PECHUGA', 'categoria_id' => 1, 'precio' => 1, 'cantidad_existencia' => 0, 'importe_existencia' => 0, 'cantidad_entrada' => 1, 'importe_entrada' => 1, 'cantidad_salida' => 0, 'importe_salida' => 0, 'cantidad_sobrante' => 0, 'importe_sobrante' => 0]])
        ->call('guardar')
        ->assertReturned(false);

    expect(Cuenta::count())->toBe(1);
});

test('un capturista no puede sobrescribir una cuenta ya capturada de otra fecha', function () {
    $hoy = DiaDeNegocio::hoy();
    $fecha = $hoy->subDays(2)->toDateString();

    $componente = Livewire::actingAs($this->admin)->test('pages::cuentas.registrar')
        ->set('sucursalId', $this->sucursalA->id)
        ->set('fechaVenta', $fecha)
        ->call('presentar')
        ->set('efectivoEntregado', 500)
        ->call('guardar');

    expect((float) Cuenta::whereDate('fecha_venta', $fecha)->first()->efectivo_entregado)->toBe(500.0);

    Livewire::actingAs($this->capturista)->test('pages::cuentas.registrar')
        ->set('fechaVenta', $fecha)
        ->call('presentar')
        ->set('efectivoEntregado', 1)
        ->call('guardar')
        ->assertReturned(false);

    expect((float) Cuenta::whereDate('fecha_venta', $fecha)->first()->efectivo_entregado)->toBe(500.0);
});

test('un capturista no puede capturar fechas futuras ni muy antiguas', function (int $dias) {
    Livewire::actingAs($this->capturista)->test('pages::cuentas.registrar')
        ->set('fechaVenta', DiaDeNegocio::hoy()->addDays($dias)->toDateString())
        ->call('presentar')
        ->assertSet('buttonsDisabled', true);
})->with([1, -10]);

test('al editar una cuenta, un gasto quitado en pantalla se elimina de la base', function () {
    $cuenta = cuentaVacia($this->sucursalA, DiaDeNegocio::hoy()->subDay()->toDateString());
    Gasto::create(['concepto' => 'Gas', 'precio' => 100, 'sucursal_id' => $this->sucursalA->id, 'cuenta_id' => $cuenta->id]);
    Gasto::create(['concepto' => 'Hielo', 'precio' => 30, 'sucursal_id' => $this->sucursalA->id, 'cuenta_id' => $cuenta->id]);

    Livewire::actingAs($this->admin)->test('pages::cuentas.registrar', ['cuenta' => $cuenta])
        ->call('removeGasto', 0)
        ->call('guardar');

    expect(Gasto::where('cuenta_id', $cuenta->id)->pluck('concepto')->all())->toBe(['HIELO'])
        ->and((float) $cuenta->fresh()->total_venta)->toBe(-30.0);
});

test('conceptos repetidos se suman en un solo gasto', function () {
    $componente = Livewire::actingAs($this->capturista)->test('pages::cuentas.registrar')->call('presentar');

    $componente->set('gastos', [['concepto' => 'Gas', 'precio' => 40], ['concepto' => 'Gas', 'precio' => 60]])->call('guardar');

    expect(Gasto::count())->toBe(1)
        ->and((float) Gasto::first()->precio)->toBe(100.0)
        ->and((float) Cuenta::first()->total_venta)->toBe(-100.0);
});

test('un renglon con producto repetido o inexistente se rechaza', function () {
    $componente = Livewire::actingAs($this->capturista)->test('pages::cuentas.registrar')->call('presentar');
    $item = $componente->get('items')[0];

    $componente->set('items', [$item, $item])->call('guardar')->assertReturned(false);
    $componente->set('items', [array_merge($item, ['producto_id' => 987654])])->call('guardar')->assertReturned(false);

    expect(Cuenta::count())->toBe(0);
});

test('un capturista no puede gestionar el catalogo aunque active canManageCatalogo', function () {
    Livewire::actingAs($this->capturista)->test('pages::productos.index')
        ->set('canManageCatalogo', true);
})->throws(Exception::class, 'Cannot update locked property');

test('un capturista no puede crear productos', function () {
    Livewire::actingAs($this->capturista)->test('pages::productos.index')
        ->set('form.name', 'NUEVO')
        ->set('form.categoria_id', Categoria::first()->id)
        ->call('crear')
        ->assertStatus(403);

    expect(Producto::where('name', 'NUEVO')->exists())->toBeFalse();
});

test('los precios maestros rechazan valores negativos o no numericos', function (mixed $precio) {
    Livewire::actingAs($this->capturista)->test('pages::productos.index')
        ->set('precios', [['id' => $this->producto->id, 'name' => 'x', 'precio' => $precio]])
        ->call('guardarPrecios')
        ->assertHasErrors();
})->with(['-50', 'abc']);

test('un admin no puede ver, editar ni deshabilitar a un Super Admin', function () {
    $superAdmin = User::factory()->create(['activo' => true]);
    $superAdmin->assignRole('Super Admin');

    Livewire::actingAs($this->admin)->test('pages::usuarios.index')->call('abrirEditar', $superAdmin->id)->assertStatus(403);
    Livewire::actingAs($this->admin)->test('pages::usuarios.index')->call('toggleActivo', $superAdmin->id)->assertStatus(403);

    expect($superAdmin->fresh()->activo)->toBeTrue()
        ->and($superAdmin->fresh()->hasRole('Super Admin'))->toBeTrue();
});

test('un Super Admin si puede editar a otro Super Admin', function () {
    $uno = User::factory()->create();
    $uno->assignRole('Super Admin');
    $otro = User::factory()->create(['activo' => true]);
    $otro->assignRole('Super Admin');

    Livewire::actingAs($uno)->test('pages::usuarios.index')->call('toggleActivo', $otro->id);

    expect($otro->fresh()->activo)->toBeFalse();
});

test('abrir el detalle de una cuenta no modifica la base', function () {
    $cuenta = cuentaVacia($this->sucursalA, '2026-02-01', ['total_venta' => 7777]);

    $this->actingAs($this->admin)->get(route('admin.cuentas.show', $cuenta))->assertOk();

    expect((float) $cuenta->fresh()->total_venta)->toBe(7777.0);
});

test('un usuario deshabilitado con la sesion abierta es expulsado', function () {
    $this->actingAs($this->admin);
    $this->admin->update(['activo' => false]);

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('el dia de negocio usa la hora de Mexico y no UTC', function () {
    $this->travelTo(now()->setDate(2026, 11, 14)->setTime(0, 14)); // 00:14 UTC = 18:14 del 13 en México

    expect(DiaDeNegocio::hoy()->toDateString())->toBe('2026-11-13')
        ->and(DiaDeNegocio::inicioUtc()->toDateTimeString())->toBe('2026-11-13 06:00:00');

    Livewire::actingAs($this->capturista)->test('pages::cuentas.registrar')
        ->assertSet('fechaVenta', '2026-11-13');
});

test('guardar actualiza los renglones existentes sin duplicarlos ni borrar lo de mayoreo', function () {
    $componente = Livewire::actingAs($this->admin)->test('pages::cuentas.registrar')
        ->set('sucursalId', $this->sucursalA->id)
        ->call('presentar');

    $items = $componente->get('items');
    $items[0]['precio'] = 10;
    $items[0]['cantidad_entrada'] = 2;
    $componente->set('items', $items)->call('guardar');

    $cuenta = Cuenta::firstOrFail();
    $cuenta->itemsCuenta()->update(['cantidad_mayoreo' => 7]);

    $componente2 = Livewire::actingAs($this->admin)->test('pages::cuentas.registrar', ['cuenta' => $cuenta]);
    $items = $componente2->get('items');
    $items[0]['cantidad_entrada'] = 5;
    $componente2->set('items', $items)->call('guardar');

    $renglon = $cuenta->itemsCuenta()->where('producto_id', $this->producto->id)->get();

    expect($renglon)->toHaveCount(1)
        ->and((float) $renglon[0]->cantidad_entrada)->toBe(5.0)
        ->and((float) $renglon[0]->importe_entrada)->toBe(50.0)
        ->and((float) $renglon[0]->cantidad_mayoreo)->toBe(7.0);
});

test('el PDF de un reporte con demasiados registros se rechaza y el CSV sigue disponible', function () {
    $hoy = DiaDeNegocio::hoy();

    foreach (range(0, 260) as $i) {
        cuentaVacia($this->sucursalA, $hoy->subDays($i)->toDateString(), ['total_venta' => 100]);
    }

    $filtros = ['tipo' => 'ventas', 'desde' => $hoy->subDays(300)->toDateString(), 'hasta' => $hoy->toDateString()];

    $this->actingAs($this->admin)->get(route('admin.reportes.descargar', [...$filtros, 'formato' => 'pdf']))->assertStatus(422);
    $this->actingAs($this->admin)->get(route('admin.reportes.descargar', [...$filtros, 'formato' => 'csv']))->assertOk();

    Livewire::actingAs($this->admin)->test('pages::reportes.index')
        ->set('desde', $filtros['desde'])
        ->set('hasta', $filtros['hasta'])
        ->assertSeeHtml('data-test="aviso-pdf-grande"');
});
