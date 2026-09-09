<?php

use App\Models\Categoria;
use App\Models\Configuracion;
use App\Models\Direccion;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Domain\Cuentas\Actions\InterpretarFormularioCuentaAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function fakeGeminiResponse(array $datos): void
{
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [
                ['content' => ['parts' => [['text' => json_encode($datos)]]]],
            ],
        ]),
    ]);
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config(['services.gemini.key' => 'fake-key']);
});

test('interpretar accion lanza excepcion cuando no hay imagenes', function () {
    expect(fn () => (new InterpretarFormularioCuentaAction)([], [1 => 'POLLO']))
        ->toThrow(RuntimeException::class, 'No se recibió ninguna foto para analizar.');
});

test('interpretar accion lanza excepcion cuando no hay api key configurada', function () {
    config(['services.gemini.key' => null]);

    expect(fn () => (new InterpretarFormularioCuentaAction)([['contenido' => 'contenido', 'mime_type' => 'image/jpeg']], [1 => 'POLLO']))
        ->toThrow(RuntimeException::class);
});

test('interpretar accion reintenta cuando gemini responde 503 y despues funciona', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['code' => 503, 'message' => 'overloaded']], 503)
            ->push([
                'candidates' => [
                    ['content' => ['parts' => [['text' => json_encode([
                        'entradas' => [], 'salidas' => [], 'sobrantes' => [], 'gastos' => [], 'mermas' => [],
                    ])]]]],
                ],
            ], 200),
    ]);

    $resultado = (new InterpretarFormularioCuentaAction)([['contenido' => 'contenido', 'mime_type' => 'image/jpeg']], [1 => 'POLLO']);

    expect($resultado)->toHaveKey('entradas');
    Http::assertSentCount(2);
});

test('interpretar accion agota reintentos y lanza un error legible', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 503, 'message' => 'overloaded']], 503),
    ]);

    expect(fn () => (new InterpretarFormularioCuentaAction)([['contenido' => 'contenido', 'mime_type' => 'image/jpeg']], [1 => 'POLLO']))
        ->toThrow(RuntimeException::class, 'El servicio de IA está saturado en este momento. Intenta de nuevo en unos segundos.');

    // 2 intentos con el modelo principal + 2 con el de respaldo antes de rendirse.
    Http::assertSentCount(4);
});

test('interpretar accion usa el modelo de respaldo cuando el principal esta saturado', function () {
    config(['services.gemini.model' => 'modelo-principal']);
    config(['services.gemini.fallback_model' => 'modelo-respaldo']);

    Http::fake([
        'generativelanguage.googleapis.com/v1beta/models/modelo-principal:generateContent' => Http::response(['error' => ['code' => 503]], 503),
        'generativelanguage.googleapis.com/v1beta/models/modelo-respaldo:generateContent' => Http::response([
            'candidates' => [
                ['content' => ['parts' => [['text' => json_encode([
                    'entradas' => [], 'salidas' => [], 'sobrantes' => [], 'gastos' => [], 'mermas' => [],
                ])]]]],
            ],
        ], 200),
    ]);

    $resultado = (new InterpretarFormularioCuentaAction)([['contenido' => 'contenido', 'mime_type' => 'image/jpeg']], [1 => 'POLLO']);

    expect($resultado)->toHaveKey('entradas');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'modelo-respaldo'));
});

test('interpretar accion parsea el json devuelto por gemini', function () {
    fakeGeminiResponse([
        'entradas' => [['producto_id' => 1, 'nombre_detectado' => 'POLLO', 'cantidad' => 14.16]],
        'salidas' => [],
        'sobrantes' => [],
        'gastos' => [],
        'mermas' => [],
        'efectivo_entregado' => 8500,
        'tarjeta' => 1151,
    ]);

    $resultado = (new InterpretarFormularioCuentaAction)([['contenido' => 'contenido', 'mime_type' => 'image/jpeg']], [1 => 'POLLO']);

    expect($resultado['entradas'][0]['cantidad'])->toBe(14.16)
        ->and($resultado['efectivo_entregado'])->toBe(8500);
});

test('interpretar accion envia todas las imagenes en una sola llamada', function () {
    fakeGeminiResponse([
        'entradas' => [], 'salidas' => [], 'sobrantes' => [], 'gastos' => [], 'mermas' => [],
    ]);

    (new InterpretarFormularioCuentaAction)([
        ['contenido' => 'pagina-1', 'mime_type' => 'image/jpeg'],
        ['contenido' => 'pagina-2', 'mime_type' => 'image/png'],
    ], [1 => 'POLLO']);

    Http::assertSentCount(1);
    Http::assertSent(function ($request) {
        $parts = $request->data()['contents'][0]['parts'];

        return count($parts) === 3
            && $parts[1]['inline_data']['data'] === base64_encode('pagina-1')
            && $parts[2]['inline_data']['data'] === base64_encode('pagina-2');
    });
});

test('procesar foto del formulario precarga entradas, sobrantes, gastos y efectivo', function () {
    $direccion = Direccion::create([
        'codigo_postal' => '09837',
        'colonia' => 'San Miguel',
        'estado' => 'CDMX',
        'numero_interior' => 'S/N',
        'numero_exterior' => 'S/N',
        'calle' => 'Calle 1',
    ]);
    $sucursal = Sucursal::create(['name' => 'ESCUADRON 201', 'direccion_id' => $direccion->id, 'activo' => true]);

    $categoria = Categoria::factory()->create();
    $pollo = Producto::factory()->create(['name' => 'POLLO ENTERO', 'categoria_id' => $categoria->id, 'precio' => 30]);
    $pechuga = Producto::factory()->create(['name' => 'PECHUGA', 'categoria_id' => $categoria->id, 'precio' => 60]);

    $capturista = User::factory()->create(['sucursal_id' => $sucursal->id]);
    $capturista->assignRole('Capturista');

    fakeGeminiResponse([
        'entradas' => [
            ['producto_id' => $pollo->id, 'nombre_detectado' => 'POLLO', 'cantidad' => 14.16],
            ['producto_id' => null, 'nombre_detectado' => 'AMB. POLLO', 'cantidad' => 2.12],
        ],
        'salidas' => [],
        'sobrantes' => [
            ['producto_id' => $pechuga->id, 'nombre_detectado' => 'PECHUGA', 'cantidad' => 21.44],
        ],
        'gastos' => [
            ['concepto' => 'COMIDA', 'precio' => 120],
        ],
        'mermas' => [],
        'efectivo_entregado' => 8500,
        'tarjeta' => 1151,
    ]);

    $fotos = [UploadedFile::fake()->image('formulario-1.jpg'), UploadedFile::fake()->image('formulario-2.jpg')];

    $component = Livewire::actingAs($capturista)
        ->test('pages::cuentas.registrar')
        ->call('presentar')
        ->call('aplicarNuevosPrecios')
        ->set('fotosFormulario', $fotos)
        ->call('procesarFotoFormulario')
        ->assertHasNoErrors();

    $items = collect($component->get('items'))->keyBy('producto_id');

    expect((float) $items[$pollo->id]['cantidad_entrada'])->toBe(14.16)
        ->and((float) $items[$pollo->id]['importe_entrada'])->toBe(round(14.16 * 30, 3))
        ->and((float) $items[$pechuga->id]['cantidad_sobrante'])->toBe(21.44)
        ->and($component->get('gastos'))->toBe([['concepto' => 'COMIDA', 'precio' => 120.0]])
        ->and((float) $component->get('efectivoEntregado'))->toBe(8500.0)
        ->and((float) $component->get('tarjeta'))->toBe(1151.0)
        ->and($component->get('renglonesNoReconocidos'))->toContain('AMB. POLLO (2.12)');

    $camposAutodetectados = $component->get('camposAutodetectados');
    $indexPollo = collect($component->get('items'))->search(fn ($i) => $i['producto_id'] === $pollo->id);
    $indexPechuga = collect($component->get('items'))->search(fn ($i) => $i['producto_id'] === $pechuga->id);

    expect($camposAutodetectados)->toHaveKey("items.{$indexPollo}.cantidad_entrada")
        ->and($camposAutodetectados)->toHaveKey("items.{$indexPechuga}.cantidad_sobrante")
        ->and($camposAutodetectados)->toHaveKey('gastos.0')
        ->and($camposAutodetectados)->toHaveKey('efectivoEntregado')
        ->and($camposAutodetectados)->toHaveKey('tarjeta');
});

test('procesar foto sin api key configurada muestra un error sin romper el formulario', function () {
    config(['services.gemini.key' => null]);

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

    $foto = UploadedFile::fake()->image('formulario.jpg');

    Livewire::actingAs($capturista)
        ->test('pages::cuentas.registrar')
        ->set('fotosFormulario', [$foto])
        ->call('procesarFotoFormulario')
        ->assertSet('camposAutodetectados', []);
});

test('se puede quitar una foto individual antes de procesar', function () {
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

    $fotos = [
        UploadedFile::fake()->image('pagina-1.jpg'),
        UploadedFile::fake()->image('pagina-2.jpg'),
        UploadedFile::fake()->image('pagina-3.jpg'),
    ];

    $component = Livewire::actingAs($capturista)
        ->test('pages::cuentas.registrar')
        ->set('fotosFormulario', $fotos)
        ->call('eliminarFotoFormulario', 1);

    $restantes = collect($component->get('fotosFormulario'))->map->getClientOriginalName()->values();

    expect($restantes->all())->toBe(['pagina-1.jpg', 'pagina-3.jpg']);
});

test('se puede procesar un pdf escaneado del formulario en lugar de fotos', function () {
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

    fakeGeminiResponse([
        'entradas' => [], 'salidas' => [], 'sobrantes' => [], 'gastos' => [], 'mermas' => [],
    ]);

    $pdf = UploadedFile::fake()->create('formulario.pdf', 500, 'application/pdf');

    Livewire::actingAs($capturista)
        ->test('pages::cuentas.registrar')
        ->call('presentar')
        ->set('fotosFormulario', [$pdf])
        ->call('procesarFotoFormulario')
        ->assertHasNoErrors();

    Http::assertSent(fn ($request) => $request->data()['contents'][0]['parts'][1]['inline_data']['mime_type'] === 'application/pdf');
});

test('rechaza tipos de archivo que no son foto ni pdf', function () {
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

    $archivo = UploadedFile::fake()->create('formulario.txt', 10, 'text/plain');

    Livewire::actingAs($capturista)
        ->test('pages::cuentas.registrar')
        ->set('fotosFormulario', [$archivo])
        ->call('procesarFotoFormulario')
        ->assertHasErrors(['fotosFormulario.0']);
});

test('no procesa la foto cuando el super admin deshabilito la ia', function () {
    Configuracion::activar(Configuracion::IA_CAPTURA_HABILITADA, false);

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

    $foto = UploadedFile::fake()->image('formulario.jpg');

    Livewire::actingAs($capturista)
        ->test('pages::cuentas.registrar')
        ->assertSet('iaCapturaHabilitada', false)
        ->set('fotosFormulario', [$foto])
        ->call('procesarFotoFormulario')
        ->assertSet('camposAutodetectados', []);

    Http::assertNothingSent();
});
