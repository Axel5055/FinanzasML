<?php

use App\Models\Categoria;
use App\Models\Direccion;
use App\Models\Execution;
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
    $this->pechuga = Producto::factory()->create(['name' => 'PECHUGA', 'categoria_id' => $categoria->id]);
    $this->tender = Producto::factory()->create(['name' => 'TENDER', 'categoria_id' => $categoria->id]);
});

function crearSucursalParaBorrador(string $nombre, bool $activa = true): Sucursal
{
    $direccion = Direccion::create([
        'codigo_postal' => '09837', 'colonia' => 'San Miguel', 'estado' => 'CDMX',
        'numero_interior' => 'S/N', 'numero_exterior' => 'S/N', 'calle' => 'Calle 1',
    ]);

    return Sucursal::create(['name' => $nombre, 'direccion_id' => $direccion->id, 'activo' => $activa]);
}

function crearCapturistaParaBorrador(Sucursal $sucursal): User
{
    $capturista = User::factory()->create(['sucursal_id' => $sucursal->id]);
    $capturista->assignRole('Capturista');

    return $capturista;
}

/**
 * @return array<string, mixed>
 */
function borradorDePrueba(Producto $pechuga, Producto $tender, array $cambios = []): array
{
    return array_merge([
        'version' => 1,
        'sucursalId' => 999,
        'fechaVenta' => '2026-03-10',
        'step' => 4,
        'maxStepReached' => 4,
        'items' => [
            ['producto_id' => $pechuga->id, 'precio' => 100, 'cantidad_entrada' => 5, 'cantidad_sobrante' => 2],
            ['producto_id' => $tender->id, 'precio' => 50, 'cantidad_entrada' => '1.5', 'cantidad_sobrante' => ''],
            ['producto_id' => 987654, 'precio' => 1, 'cantidad_entrada' => 1, 'cantidad_sobrante' => 1],
        ],
        'gastos' => [['concepto' => 'Gas', 'precio' => 250]],
        'mermas' => [['concepto' => 'Pollo caído', 'precio' => 80]],
        'efectivoEntregado' => 300,
        'tarjeta' => 40,
    ], $cambios);
}

function itemDelComponente($componente, Producto $producto): array
{
    return collect($componente->get('items'))->firstWhere('producto_id', $producto->id);
}

test('un capturista retoma su borrador: se cargan cantidades, gastos, mermas, pagos y el paso', function () {
    $sucursal = crearSucursalParaBorrador('NORTE');
    $capturista = crearCapturistaParaBorrador($sucursal);

    $componente = Livewire::actingAs($capturista)
        ->test('pages::cuentas.registrar')
        ->call('restaurarBorrador', borradorDePrueba($this->pechuga, $this->tender))
        ->assertReturned(true)
        ->assertSet('buttonsDisabled', false)
        ->assertSet('step', 4)
        ->assertSet('maxStepReached', 4)
        ->assertSet('efectivoEntregado', 300.0)
        ->assertSet('tarjeta', 40.0)
        ->assertSet('gastos', [['concepto' => 'Gas', 'precio' => 250.0]])
        ->assertSet('mermas', [['concepto' => 'Pollo caído', 'precio' => 80.0]]);

    $pechuga = itemDelComponente($componente, $this->pechuga);
    $tender = itemDelComponente($componente, $this->tender);

    expect($pechuga['precio'])->toBe(100.0)
        ->and($pechuga['cantidad_entrada'])->toBe(5.0)
        ->and($pechuga['importe_entrada'])->toBe(500.0)
        ->and($pechuga['importe_sobrante'])->toBe(200.0)
        ->and($tender['cantidad_entrada'])->toBe(1.5)
        ->and($tender['importe_entrada'])->toBe(75.0)
        ->and($tender['cantidad_sobrante'])->toBe(0.0)
        ->and($componente->get('sumEntrada'))->toBe(575.0)
        ->and($componente->get('sumSobrante'))->toBe(200.0);
});

test('la sucursal de un capturista siempre es la suya aunque el borrador diga otra', function () {
    $propia = crearSucursalParaBorrador('PROPIA');
    $ajena = crearSucursalParaBorrador('AJENA');
    $capturista = crearCapturistaParaBorrador($propia);

    Livewire::actingAs($capturista)
        ->test('pages::cuentas.registrar')
        ->call('restaurarBorrador', borradorDePrueba($this->pechuga, $this->tender, ['sucursalId' => $ajena->id]))
        ->assertReturned(true)
        ->assertSet('sucursalId', $propia->id)
        ->assertSet('fechaVenta', '2026-03-10');
});

test('un admin retoma el borrador con la sucursal y la fecha guardadas', function () {
    $sucursal = crearSucursalParaBorrador('SUR');
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    Livewire::actingAs($admin)
        ->test('pages::cuentas.registrar')
        ->call('restaurarBorrador', borradorDePrueba($this->pechuga, $this->tender, ['sucursalId' => $sucursal->id]))
        ->assertReturned(true)
        ->assertSet('sucursalId', $sucursal->id)
        ->assertSet('fechaVenta', '2026-03-10')
        ->assertSet('buttonsDisabled', false);
});

test('un borrador con sucursal inactiva, inexistente o fecha invalida se rechaza sin tocar el formulario', function (array $cambios) {
    $inactiva = crearSucursalParaBorrador('CERRADA', activa: false);
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $cambios = array_map(fn ($valor) => $valor === 'INACTIVA' ? $inactiva->id : $valor, $cambios);

    Livewire::actingAs($admin)
        ->test('pages::cuentas.registrar')
        ->call('restaurarBorrador', borradorDePrueba($this->pechuga, $this->tender, $cambios))
        ->assertReturned(false)
        ->assertSet('buttonsDisabled', true)
        ->assertSet('items', []);
})->with([
    'sucursal inactiva' => [['sucursalId' => 'INACTIVA']],
    'sucursal inexistente' => [['sucursalId' => 424242]],
    'fecha con basura' => [['sucursalId' => 'INACTIVA', 'fechaVenta' => 'ayer']],
    'fecha imposible' => [['fechaVenta' => '2026-02-31']],
]);

test('los datos manipulados del borrador se sanean antes de usarse', function () {
    $sucursal = crearSucursalParaBorrador('NORTE');
    $capturista = crearCapturistaParaBorrador($sucursal);

    $gastos = array_fill(0, 80, ['concepto' => 'X', 'precio' => 1]);
    $gastos[0] = ['concepto' => ['no', 'es', 'texto'], 'precio' => 'abc'];
    $gastos[1] = ['concepto' => str_repeat('A', 400), 'precio' => 5e20];
    $gastos[2] = 'no soy un renglón';

    $componente = Livewire::actingAs($capturista)
        ->test('pages::cuentas.registrar')
        ->call('restaurarBorrador', borradorDePrueba($this->pechuga, $this->tender, [
            'step' => 99,
            'maxStepReached' => -5,
            'gastos' => $gastos,
            'mermas' => 'no es una lista',
            'efectivoEntregado' => 'mucho',
            'tarjeta' => ['x'],
            'items' => [
                ['producto_id' => $this->pechuga->id, 'precio' => '<script>', 'cantidad_entrada' => null, 'cantidad_sobrante' => 3],
                'basura',
            ],
        ]))
        ->assertReturned(true)
        ->assertSet('step', 7)
        ->assertSet('mermas', [['concepto' => '', 'precio' => 0]])
        ->assertSet('efectivoEntregado', 0.0)
        ->assertSet('tarjeta', 0.0);

    $gastosGuardados = $componente->get('gastos');
    $pechuga = itemDelComponente($componente, $this->pechuga);

    expect($gastosGuardados)->toHaveCount(50)
        ->and($gastosGuardados[0])->toBe(['concepto' => '', 'precio' => 0.0])
        ->and(mb_strlen($gastosGuardados[1]['concepto']))->toBe(255)
        ->and($gastosGuardados[1]['precio'])->toBe(999999999.0)
        ->and($pechuga['precio'])->toBe(0.0)
        ->and($pechuga['cantidad_entrada'])->toBe(0.0)
        ->and($pechuga['cantidad_sobrante'])->toBe(3.0);
});

test('un capturista que ya registro su cuenta hoy no puede retomar un borrador', function () {
    $sucursal = crearSucursalParaBorrador('NORTE');
    $capturista = crearCapturistaParaBorrador($sucursal);

    Execution::create(['function_name' => 'presentar', 'user_id' => $capturista->id, 'last_executed_at' => now()]);

    Livewire::actingAs($capturista)
        ->test('pages::cuentas.registrar')
        ->call('restaurarBorrador', borradorDePrueba($this->pechuga, $this->tender))
        ->assertReturned(false)
        ->assertSet('buttonsDisabled', true);
});

test('al volver la conexion se recalculan todos los importes de lo capturado sin internet', function () {
    $sucursal = crearSucursalParaBorrador('NORTE');
    $capturista = crearCapturistaParaBorrador($sucursal);

    $componente = Livewire::actingAs($capturista)
        ->test('pages::cuentas.registrar')
        ->call('presentar');

    $items = $componente->get('items');

    foreach ($items as $indice => $item) {
        $items[$indice]['precio'] = 10;
        $items[$indice]['cantidad_entrada'] = 2;
        $items[$indice]['cantidad_sobrante'] = 1;
    }

    // Se fija el arreglo completo: ningún hook updated() de renglón se ejecuta, como al reconectar.
    $componente->set('items', $items);
    expect($componente->get('sumEntrada'))->toBe(0.0);

    $componente->call('recuperarConexion')
        ->assertSet('sumEntrada', 40.0)
        ->assertSet('sumSobrante', 20.0);
});

test('guardar la cuenta responde verdadero para que el navegador limpie el borrador', function () {
    $sucursal = crearSucursalParaBorrador('NORTE');
    $capturista = crearCapturistaParaBorrador($sucursal);

    Livewire::actingAs($capturista)
        ->test('pages::cuentas.registrar')
        ->call('presentar')
        ->call('guardar')
        ->assertReturned(true);
});

test('la pantalla incluye el aviso de desconexion y la oferta de borrador', function () {
    $sucursal = crearSucursalParaBorrador('NORTE');
    $capturista = crearCapturistaParaBorrador($sucursal);

    $this->actingAs($capturista)
        ->get(route('capturista.registrar.index'))
        ->assertOk()
        ->assertSee('borradorCuenta('.$capturista->id, false)
        ->assertSee('data-test="aviso-conexion"', false)
        ->assertSee('data-test="reintentar-conexion"', false)
        ->assertSee('data-test="oferta-borrador"', false)
        ->assertSee('Encontramos una cuenta sin terminar')
        ->assertSee('Se conserva 2 días');
});

test('los dialogos nacen ocultos para no bloquear la pantalla si Alpine falla o el JS esta desactualizado', function () {
    $sucursal = crearSucursalParaBorrador('NORTE');
    $capturista = crearCapturistaParaBorrador($sucursal);

    $html = $this->actingAs($capturista)
        ->get(route('capturista.registrar.index'))
        ->assertOk()
        ->getContent();

    foreach (['aviso-conexion', 'oferta-borrador'] as $dialogo) {
        expect($html)->toMatch('/<div\s[^>]*style="display: none;"[^>]*data-test="'.$dialogo.'"/s');
    }
});

test('el borrador local caduca a los 2 dias', function () {
    $js = file_get_contents(resource_path('js/borrador-cuenta.js'));

    expect($js)->toContain('const VIGENCIA_MS = 2 * 24 * 60 * 60 * 1000;');
});
