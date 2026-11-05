<?php

use App\Models\Cuenta;
use App\Models\Direccion;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StatusCuentaSeeder;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard does not show repository or documentation links', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertDontSee('Repositorio');
    $response->assertDontSee('Documentación');
    $response->assertDontSee('github.com/laravel/livewire-starter-kit', false);
    $response->assertDontSee('laravel.com/docs/starter-kits', false);
});

test('un admin ve el resumen anual, el top de sucursales y el acceso a registrar cuenta', function () {
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Resumen anual')
        ->assertSee('Top sucursales')
        ->assertSee('Actividad reciente')
        ->assertDontSee('Exportar reporte')
        ->assertSee(route('admin.registrar.index'), false);
});

test('un capturista ve su resumen sin las secciones de administrador', function () {
    $this->seed(RoleSeeder::class);

    $capturista = User::factory()->create();
    $capturista->assignRole('Capturista');

    $this->actingAs($capturista)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Estatus de cuentas')
        ->assertSee(route('capturista.registrar.index'), false)
        ->assertDontSee('Resumen anual')
        ->assertDontSee('Top sucursales');
});

test('la barra lateral muestra la marca ML Grupo Comercial con su logo', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('ML Grupo')
        ->assertSee('Comercial')
        ->assertSee('icons/icon-192.png', false);
});

function crearVentaDePrueba(int $sucursalId, string $fecha, float $total): void
{
    Cuenta::unguarded(function () use ($sucursalId, $fecha, $total) {
        Cuenta::create([
            'efectivo_pollo' => 0,
            'efectivo_marinado' => 0,
            'efectivo_total' => 0,
            'efectivo_entregado' => 0,
            'total_venta' => $total,
            'tarjeta' => 0,
            'diferencia' => 0,
            'sobrante' => 0,
            'fecha_captura' => $fecha,
            'fecha_venta' => $fecha,
            'sucursal_id' => $sucursalId,
            'status_cuenta_id' => 1,
        ]);
    });
}

function crearSucursalParaGrafica(string $nombre): Sucursal
{
    $direccion = Direccion::create([
        'codigo_postal' => '09837',
        'colonia' => 'San Miguel',
        'estado' => 'CDMX',
        'numero_interior' => 'S/N',
        'numero_exterior' => 'S/N',
        'calle' => 'Calle 1',
    ]);

    return Sucursal::create(['name' => $nombre, 'direccion_id' => $direccion->id, 'activo' => true]);
}

test('la grafica anual se actualiza al cambiar la sucursal o el año sin boton de filtrar', function () {
    $this->seed(RoleSeeder::class);
    $this->seed(StatusCuentaSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $norte = crearSucursalParaGrafica('NORTE');
    $sur = crearSucursalParaGrafica('SUR');
    $anio = now()->year;
    $anterior = $anio - 1;

    crearVentaDePrueba($norte->id, "{$anio}-01-10", 1000);
    crearVentaDePrueba($sur->id, "{$anio}-01-12", 500);
    crearVentaDePrueba($norte->id, "{$anterior}-01-10", 600);

    $component = Livewire::actingAs($admin)->test('pages::dashboard');

    $component->assertDontSee('Filtrar');

    $columnas = collect($component->get('chartData')['series'])->where('type', 'column');
    expect($columnas->pluck('name')->all())->toBe(['NORTE', 'SUR'])
        ->and($columnas->firstWhere('name', 'NORTE')['data'][0])->toBe(1000.0);

    $component->set('chartSucursalId', (string) $sur->id);

    $columnas = collect($component->get('chartData')['series'])->where('type', 'column');
    expect($columnas->pluck('name')->all())->toBe(['SUR']);

    $component->set('chartSucursalId', '')->set('chartYear', (string) $anterior);

    expect($component->get('chartData')['anio'])->toBe($anterior)
        ->and(collect($component->get('chartData')['series'])->where('type', 'column')->firstWhere('name', 'NORTE')['data'][0])->toBe(600.0);
});

test('el resumen anual calcula total, promedio, mejor mes y variacion contra el año anterior', function () {
    $this->seed(RoleSeeder::class);
    $this->seed(StatusCuentaSeeder::class);
    $this->travelTo(now()->setDate(2026, 3, 15));

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $sucursal = crearSucursalParaGrafica('NORTE');

    crearVentaDePrueba($sucursal->id, '2026-01-10', 1000);
    crearVentaDePrueba($sucursal->id, '2026-02-10', 3000);
    crearVentaDePrueba($sucursal->id, '2025-01-10', 1000);
    crearVentaDePrueba($sucursal->id, '2025-02-10', 1000);
    crearVentaDePrueba($sucursal->id, '2025-08-10', 9999);

    $component = Livewire::actingAs($admin)->test('pages::dashboard');
    $resumen = $component->get('chartResumen');

    expect($resumen['total'])->toBe(4000.0)
        ->and($resumen['promedio'])->toBe(4000.0 / 3)
        ->and($resumen['mejorMes'])->toBe('febrero')
        ->and($resumen['mejorMonto'])->toBe(3000.0)
        ->and($resumen['anterior'])->toBe(2000.0)
        ->and($resumen['variacion'])->toBe(100.0)
        ->and($resumen['periodo'])->toBe('Enero a marzo');

    $linea = collect($component->get('chartData')['series'])->firstWhere('type', 'line');
    expect($linea['name'])->toBe('Total 2025')
        ->and($linea['data'][7])->toBe(9999.0);

    $columna = collect($component->get('chartData')['series'])->firstWhere('type', 'column');
    expect($columna['data'][3])->toBeNull();
});

test('un capturista no puede pedir los datos de la grafica anual', function () {
    $this->seed(RoleSeeder::class);

    $capturista = User::factory()->create();
    $capturista->assignRole('Capturista');

    Livewire::actingAs($capturista)
        ->test('pages::dashboard')
        ->call('actualizarGrafica')
        ->assertStatus(403);
});
