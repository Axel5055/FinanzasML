<?php

use App\Actions\Permisos\SeccionesDelAdmin;
use App\Models\ActivityLog;
use App\Models\Categoria;
use App\Models\Cuenta;
use App\Models\Direccion;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StatusCuentaSeeder;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create(['name' => 'Ana Admin']);
    $this->admin->assignRole('Admin');
});

function crearMovimiento(User $usuario, string $descripcion, ?string $subjectType = null, ?string $fecha = null): ActivityLog
{
    $registro = ActivityLog::create([
        'user_id' => $usuario->id,
        'description' => $descripcion,
        'subject_type' => $subjectType,
        'subject_id' => $subjectType ? 1 : null,
        'ip_address' => '10.0.0.5',
    ]);

    if ($fecha) {
        $registro->forceFill(['created_at' => $fecha])->save();
    }

    return $registro;
}

test('la auditoria es solo del super admin hasta que se le habilita al rol admin', function () {
    $capturista = User::factory()->create();
    $capturista->assignRole('Capturista');

    $this->actingAs($capturista)->get(route('admin.auditoria.index'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.auditoria.index'))->assertForbidden();

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin)->get(route('admin.auditoria.index'))->assertOk()->assertSee('Auditoría');

    app(SeccionesDelAdmin::class)->cambiar('auditoria', true);

    $this->actingAs($this->admin->fresh())->get(route('admin.auditoria.index'))->assertOk();
});

test('la auditoria lista los movimientos con usuario, modulo y paginacion', function () {
    crearMovimiento($this->admin, 'editó el producto "PECHUGA"', Producto::class);

    foreach (range(1, 30) as $n) {
        crearMovimiento($this->admin, "movimiento numero {$n}");
    }

    $component = Livewire::actingAs($this->admin)->test('pages::auditoria.index');

    expect($component->get('registros')->total())->toBe(31)
        ->and($component->get('registros')->count())->toBe(25);

    $component->assertSee('Ana Admin')->assertSee('Admin')->assertSee('10.0.0.5');

    $component->set('porPagina', 50);
    expect($component->get('registros')->count())->toBe(31);

    $component->set('porPagina', 999)->assertSet('porPagina', 25);
});

test('los filtros acotan el historial por usuario, modulo, fechas y texto', function () {
    $beto = User::factory()->create(['name' => 'Beto Capturista']);
    $beto->assignRole('Capturista');

    crearMovimiento($this->admin, 'creó el producto "ALITAS"', Producto::class, '2026-03-10 09:00:00');
    crearMovimiento($beto, 'registró una cuenta', Cuenta::class, '2026-03-12 10:00:00');
    crearMovimiento($beto, 'inició sesión', null, '2026-03-12 08:00:00');
    crearMovimiento($this->admin, 'actualizó los ajustes', null, '2026-03-20 08:00:00');

    $component = Livewire::actingAs($this->admin)->test('pages::auditoria.index');
    expect($component->get('registros')->total())->toBe(4);

    $component->set('usuarioFiltro', (string) $beto->id);
    expect($component->get('registros')->total())->toBe(2);

    $component->set('usuarioFiltro', '')->set('moduloFiltro', 'productos');
    expect($component->get('registros')->pluck('description')->all())->toBe(['creó el producto "ALITAS"']);

    $component->set('moduloFiltro', 'sesiones');
    expect($component->get('registros')->pluck('description')->all())->toBe(['inició sesión']);

    $component->set('moduloFiltro', 'general');
    expect($component->get('registros')->pluck('description')->all())->toBe(['actualizó los ajustes']);

    $component->set('moduloFiltro', '')->set('desde', '2026-03-11')->set('hasta', '2026-03-15');
    expect($component->get('registros')->total())->toBe(2);

    $component->set('desde', '')->set('hasta', '')->set('buscar', 'alitas');
    expect($component->get('registros')->total())->toBe(1);

    $component->set('buscar', 'beto');
    expect($component->get('registros')->total())->toBe(2);

    $component->call('limpiarFiltros')->assertSet('buscar', '')->assertSet('moduloFiltro', '');
    expect($component->get('registros')->total())->toBe(4);
});

test('el historial se puede exportar a csv respetando los filtros', function () {
    crearMovimiento($this->admin, 'creó el producto "ALITAS"', Producto::class);
    crearMovimiento($this->admin, 'inició sesión');

    Livewire::actingAs($this->admin)
        ->test('pages::auditoria.index')
        ->set('moduloFiltro', 'productos')
        ->call('exportar')
        ->assertFileDownloaded();
});

test('el inicio y cierre de sesion quedan registrados en la bitacora', function () {
    event(new Login('web', $this->admin, false));
    event(new Logout('web', $this->admin));

    expect(ActivityLog::where('user_id', $this->admin->id)->pluck('description')->all())
        ->toBe(['inició sesión', 'cerró sesión']);
});

test('crear, editar y eliminar productos queda en la bitacora', function () {
    $categoria = Categoria::factory()->create();

    $component = Livewire::actingAs($this->admin)->test('pages::productos.index')
        ->set('form.name', 'Pechuga')
        ->set('form.categoria_id', $categoria->id)
        ->call('crear');

    $producto = Producto::firstWhere('name', 'PECHUGA');

    $component->call('abrirEditar', $producto->id)
        ->set('form.name', 'Pechuga XL')
        ->call('actualizar')
        ->call('eliminar', $producto->id);

    expect(ActivityLog::pluck('description')->all())->toBe([
        'creó el producto "PECHUGA"',
        'editó el producto "PECHUGA XL"',
        'eliminó el producto "PECHUGA XL"',
    ]);
});

test('los movimientos del detalle de una cuenta quedan en la bitacora', function () {
    $this->seed(StatusCuentaSeeder::class);

    $sucursal = Sucursal::create([
        'name' => 'NORTE',
        'direccion_id' => Direccion::create([
            'codigo_postal' => '09837', 'colonia' => 'San Miguel', 'estado' => 'CDMX',
            'numero_interior' => 'S/N', 'numero_exterior' => 'S/N', 'calle' => 'Calle 1',
        ])->id,
        'activo' => true,
    ]);

    $cuenta = Cuenta::unguarded(fn () => Cuenta::create([
        'efectivo_pollo' => 0, 'efectivo_marinado' => 0, 'efectivo_total' => 0, 'efectivo_entregado' => 0,
        'total_venta' => 100, 'tarjeta' => 0, 'diferencia' => 0, 'sobrante' => 0,
        'fecha_captura' => '2026-03-10', 'fecha_venta' => '2026-03-10',
        'sucursal_id' => $sucursal->id, 'status_cuenta_id' => 1,
    ]));

    Livewire::actingAs($this->admin)
        ->test('pages::cuentas.show', ['cuenta' => $cuenta])
        ->set('gastoForm', ['concepto' => 'Gas', 'precio' => 250])
        ->call('guardarGasto')
        ->set('statusId', 3)
        ->call('guardarStatus');

    $descripciones = ActivityLog::pluck('description')->all();

    expect($descripciones[0])->toContain('registró el gasto "Gas" por $250.00')->toContain('cuenta del 10/03/2026 (NORTE)')
        ->and($descripciones[1])->toContain('cambió el status a');
});
