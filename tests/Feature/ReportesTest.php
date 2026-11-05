<?php

use App\Actions\Reportes\GenerarReporte;
use App\Models\ActivityLog;
use App\Models\Categoria;
use App\Models\Cuenta;
use App\Models\Direccion;
use App\Models\Gasto;
use App\Models\ItemCuenta;
use App\Models\Merma;
use App\Models\Producto;
use App\Models\Salida;
use App\Models\Sucursal;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StatusCuentaSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(StatusCuentaSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Admin');

    $this->norte = sucursalParaReportes('NORTE');
    $this->sur = sucursalParaReportes('SUR');
});

function sucursalParaReportes(string $nombre): Sucursal
{
    $direccion = Direccion::create([
        'codigo_postal' => '09837', 'colonia' => 'San Miguel', 'estado' => 'CDMX',
        'numero_interior' => 'S/N', 'numero_exterior' => 'S/N', 'calle' => 'Calle 1',
    ]);

    return Sucursal::create(['name' => $nombre, 'direccion_id' => $direccion->id, 'activo' => true]);
}

function cuentaParaReportes(Sucursal $sucursal, string $fecha, float $total, float $diferencia = 0, float $efectivo = 0, float $tarjeta = 0): Cuenta
{
    return Cuenta::unguarded(fn () => Cuenta::create([
        'efectivo_pollo' => 0, 'efectivo_marinado' => 0, 'efectivo_total' => 0,
        'efectivo_entregado' => $efectivo, 'tarjeta' => $tarjeta,
        'total_venta' => $total, 'diferencia' => $diferencia, 'sobrante' => 0,
        'fecha_captura' => $fecha, 'fecha_venta' => $fecha,
        'sucursal_id' => $sucursal->id, 'status_cuenta_id' => 1,
    ]));
}

function generarReporteDePrueba(string $tipo, ?int $sucursalId = null): array
{
    return (new GenerarReporte)->ejecutar($tipo, Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'), $sucursalId);
}

test('solo admin y super admin entran a reportes y descargan archivos', function () {
    $capturista = User::factory()->create();
    $capturista->assignRole('Capturista');

    $this->actingAs($capturista)->get(route('admin.reportes.index'))->assertForbidden();
    $this->actingAs($capturista)->get(route('admin.reportes.descargar', ['tipo' => 'ventas', 'desde' => '2026-03-01', 'hasta' => '2026-03-31', 'formato' => 'csv']))->assertForbidden();

    $this->actingAs($this->admin)->get(route('admin.reportes.index'))->assertOk()->assertSee('Reportes')->assertSee('Ventas por cuenta');
});

test('el reporte de ventas lista las cuentas del periodo con sus totales', function () {
    cuentaParaReportes($this->norte, '2026-03-05', 1000, 100, 600, 300);
    cuentaParaReportes($this->sur, '2026-03-06', 500, -50, 500, 50);
    cuentaParaReportes($this->norte, '2026-03-07', 0);
    cuentaParaReportes($this->norte, '2026-04-02', 900);

    $reporte = generarReporteDePrueba('ventas');

    expect($reporte['filas'])->toHaveCount(2)
        ->and($reporte['totales']['total'])->toBe(1500.0)
        ->and($reporte['totales']['efectivo'])->toBe(1100.0)
        ->and($reporte['resumen'][1]['valor'])->toBe(1500.0)
        ->and($reporte['resumen'][2]['valor'])->toBe(100.0)
        ->and($reporte['resumen'][3]['valor'])->toBe(50.0)
        ->and($reporte['filas'][0]['status'])->toBe('Pendiente');

    expect(generarReporteDePrueba('ventas', $this->sur->id)['filas'])->toHaveCount(1);
});

test('el comparativo de sucursales calcula ticket promedio, gastos, mermas y participacion', function () {
    $cuentaNorte = cuentaParaReportes($this->norte, '2026-03-05', 1000, 100);
    cuentaParaReportes($this->norte, '2026-03-06', 2000);
    cuentaParaReportes($this->sur, '2026-03-06', 1000, -20);

    Gasto::create(['precio' => 150, 'concepto' => 'gas', 'sucursal_id' => $this->norte->id, 'cuenta_id' => $cuentaNorte->id]);
    Merma::create(['precio' => 40, 'concepto' => 'huesos', 'sucursal_id' => $this->norte->id, 'cuenta_id' => $cuentaNorte->id]);

    $reporte = generarReporteDePrueba('sucursales');
    $norte = collect($reporte['filas'])->firstWhere('sucursal', 'NORTE');

    expect($reporte['filas'][0]['sucursal'])->toBe('NORTE')
        ->and($norte['cuentas'])->toBe(2)
        ->and($norte['ventas'])->toBe(3000.0)
        ->and($norte['ticket'])->toBe(1500.0)
        ->and($norte['faltantes'])->toBe(100.0)
        ->and($norte['gastos'])->toBe(150.0)
        ->and($norte['mermas'])->toBe(40.0)
        ->and($norte['participacion'])->toBe(75.0)
        ->and($reporte['totales']['ventas'])->toBe(4000.0)
        ->and($reporte['totales']['sobrantes'])->toBe(20.0)
        ->and($reporte['resumen'][3]['valor'])->toBe('NORTE');
});

test('el reporte de faltantes y sobrantes separa cada tipo', function () {
    cuentaParaReportes($this->norte, '2026-03-05', 1000, 80);
    cuentaParaReportes($this->sur, '2026-03-06', 500, -30);
    cuentaParaReportes($this->sur, '2026-03-07', 700, 0);

    $reporte = generarReporteDePrueba('diferencias');

    expect(collect($reporte['filas'])->pluck('tipo')->all())->toBe(['Faltante', 'Sobrante'])
        ->and($reporte['resumen'][1]['valor'])->toBe(80.0)
        ->and($reporte['resumen'][3]['valor'])->toBe(30.0)
        ->and($reporte['filas'][1]['diferencia'])->toBe(30.0);
});

test('el reporte de gastos y mermas junta ambos registros por fecha', function () {
    $cuenta = cuentaParaReportes($this->norte, '2026-03-05', 1000);
    $fuera = cuentaParaReportes($this->norte, '2026-05-05', 1000);

    Gasto::create(['precio' => 150, 'concepto' => 'gas', 'sucursal_id' => $this->norte->id, 'cuenta_id' => $cuenta->id]);
    Merma::create(['precio' => 40, 'concepto' => 'huesos', 'sucursal_id' => $this->norte->id, 'cuenta_id' => $cuenta->id]);
    Gasto::create(['precio' => 999, 'concepto' => 'otro mes', 'sucursal_id' => $this->norte->id, 'cuenta_id' => $fuera->id]);

    $reporte = generarReporteDePrueba('gastos');

    expect($reporte['filas'])->toHaveCount(2)
        ->and(collect($reporte['filas'])->pluck('concepto')->all())->toBe(['GAS', 'HUESOS'])
        ->and($reporte['totales']['importe'])->toBe(190.0)
        ->and($reporte['resumen'][0]['valor'])->toBe(150.0)
        ->and($reporte['resumen'][1]['valor'])->toBe(40.0);
});

test('el reporte por producto suma entradas y sobrantes', function () {
    $categoria = Categoria::factory()->create(['name' => 'Pollo']);
    $pechuga = Producto::factory()->create(['name' => 'Pechuga', 'categoria_id' => $categoria->id]);
    $cuentaA = cuentaParaReportes($this->norte, '2026-03-05', 1000);
    $cuentaB = cuentaParaReportes($this->norte, '2026-03-06', 1000);

    foreach ([$cuentaA, $cuentaB] as $cuenta) {
        ItemCuenta::unguarded(fn () => ItemCuenta::create([
            'precio' => 50, 'cantidad_existencia' => 0, 'importe_existencia' => 0,
            'cantidad_entrada' => 10, 'importe_entrada' => 500,
            'cantidad_salida' => 0, 'importe_salida' => 0,
            'cantidad_sobrante' => 2, 'importe_sobrante' => 100,
            'cantidad_mayoreo' => 0, 'importe_mayoreo' => 0,
            'producto_id' => $pechuga->id, 'cuenta_id' => $cuenta->id, 'fecha_venta' => $cuenta->fecha_venta,
        ]));
    }

    $reporte = generarReporteDePrueba('productos');

    expect($reporte['filas'])->toHaveCount(1)
        ->and($reporte['filas'][0]['categoria'])->toBe('Pollo')
        ->and($reporte['filas'][0]['cantidad_entrada'])->toBe(20.0)
        ->and($reporte['filas'][0]['importe_entrada'])->toBe(1000.0)
        ->and($reporte['totales']['importe_sobrante'])->toBe(200.0);
});

test('el reporte de transferencias respeta origen o destino al filtrar sucursal', function () {
    $norte = $this->norte;
    $sur = $this->sur;
    $tercera = sucursalParaReportes('CENTRO');
    $producto = Producto::factory()->create();
    $cuenta = cuentaParaReportes($norte, '2026-03-05', 1000);

    foreach ([[$norte, $sur], [$sur, $tercera]] as [$origen, $destino]) {
        Salida::unguarded(fn () => Salida::create([
            'precio' => 10, 'cantidad' => 5, 'total' => 50, 'fecha_salida' => '2026-03-05',
            'producto_id' => $producto->id, 'cuenta_id' => $cuenta->id,
            'sucursal_origen_id' => $origen->id, 'sucursal_destino_id' => $destino->id,
        ]));
    }

    expect(generarReporteDePrueba('transferencias')['filas'])->toHaveCount(2)
        ->and(generarReporteDePrueba('transferencias', $norte->id)['filas'])->toHaveCount(1)
        ->and(generarReporteDePrueba('transferencias', $sur->id)['filas'])->toHaveCount(2)
        ->and(generarReporteDePrueba('transferencias')['totales']['total'])->toBe(100.0)
        ->and(generarReporteDePrueba('transferencias')['resumen'][3]['valor'])->toContain('→');
});

test('la pantalla de reportes cambia de tipo y de periodo al instante', function () {
    cuentaParaReportes($this->norte, now()->toDateString(), 1234);

    $component = Livewire::actingAs($this->admin)
        ->test('pages::reportes.index')
        ->assertSet('tipo', 'ventas')
        ->assertSet('periodo', 'mes')
        ->assertSee('$1,234.00')
        ->call('seleccionar', 'sucursales')
        ->assertSet('tipo', 'sucursales')
        ->assertSee('Comparativo de sucursales')
        ->call('seleccionar', 'inventado')
        ->assertSet('tipo', 'sucursales');

    $component->set('periodo', 'hoy')
        ->assertSet('desde', now()->toDateString())
        ->assertSet('hasta', now()->toDateString());

    $component->set('periodo', 'mes_anterior')
        ->assertSet('desde', now()->subMonthNoOverflow()->startOfMonth()->toDateString());

    $component->set('desde', '2020-01-01')->assertSet('periodo', 'personalizado');
    $component->set('sucursalId', (string) $this->sur->id)->assertSee('SUR');
});

test('la pantalla corrige un rango invertido y muestra estado vacio', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::reportes.index')
        ->set('desde', '2026-03-31')
        ->set('hasta', '2026-03-01')
        ->assertSee('Del 01/03/2026 al 31/03/2026')
        ->assertSee('No hay información para este reporte');
});

test('se descarga el reporte en csv y en pdf y queda en la bitacora', function () {
    cuentaParaReportes($this->norte, '2026-03-05', 1000, 100, 600, 300);

    $filtros = ['tipo' => 'ventas', 'desde' => '2026-03-01', 'hasta' => '2026-03-31'];

    $csv = $this->actingAs($this->admin)->get(route('admin.reportes.descargar', [...$filtros, 'formato' => 'csv']));
    $csv->assertOk()->assertDownload('reporte-ventas-por-cuenta_2026-03-01_a_2026-03-31.csv');

    $contenido = $csv->streamedContent();
    expect($contenido)->toStartWith("\xEF\xBB\xBF")
        ->toContain('Ventas por cuenta')
        ->toContain('Fecha,Sucursal,Efectivo,Tarjeta,"Total venta",Diferencia,Status')
        ->toContain('05/03/2026,NORTE,600,300,1000,100,Pendiente')
        ->toContain('Total,,600,300,1000,100');

    $pdf = $this->actingAs($this->admin)->get(route('admin.reportes.descargar', [...$filtros, 'formato' => 'pdf']));
    $pdf->assertOk()->assertDownload('reporte-ventas-por-cuenta_2026-03-01_a_2026-03-31.pdf');
    expect($pdf->streamedContent())->toStartWith('%PDF');

    expect(ActivityLog::pluck('description')->all())->toHaveCount(2)
        ->and(ActivityLog::first()->description)->toContain('exportó el reporte "Ventas por cuenta"')->toContain('CSV');
});

test('cada tipo de reporte se puede exportar a pdf sin errores', function (string $tipo) {
    $this->actingAs($this->admin)
        ->get(route('admin.reportes.descargar', ['tipo' => $tipo, 'desde' => '2026-03-01', 'hasta' => '2026-03-31', 'formato' => 'pdf']))
        ->assertOk();
})->with(array_keys(GenerarReporte::TIPOS));

test('la descarga valida el tipo, el formato y las fechas', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.reportes.descargar', ['tipo' => 'otro', 'desde' => '2026-03-01', 'hasta' => '2026-03-31', 'formato' => 'pdf']))
        ->assertSessionHasErrors('tipo');

    $this->actingAs($this->admin)
        ->get(route('admin.reportes.descargar', ['tipo' => 'ventas', 'desde' => '2026-03-31', 'hasta' => '2026-03-01', 'formato' => 'xls']))
        ->assertSessionHasErrors(['hasta', 'formato']);
});
