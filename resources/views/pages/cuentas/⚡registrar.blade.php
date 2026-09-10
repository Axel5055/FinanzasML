<?php

use App\Models\ActivityLog;
use App\Models\Configuracion;
use App\Models\Cuenta;
use App\Models\Entrada;
use App\Models\Execution;
use App\Models\Gasto;
use App\Models\ItemCuenta;
use App\Models\Merma;
use App\Models\Producto;
use App\Models\Salida;
use App\Models\Sucursal;
use Carbon\Carbon;
use Domain\Cuentas\Actions\InterpretarFormularioCuentaAction;
use Domain\Cuentas\Actions\ProcesarItemAction;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Registrar cuenta')] class extends Component {
    use WithFileUploads;

    public bool $esAdmin = false;

    public ?int $sucursalId = null;

    public string $fechaVenta;

    public string $fechaCaptura;

    public int $step = 1;

    public int $maxStepReached = 1;

    public array $steps = [
        1 => 'Existencia',
        2 => 'Entradas',
        3 => 'Salidas',
        4 => 'Gastos',
        5 => 'Merma',
        6 => 'Sobrante',
        7 => 'Totales',
    ];

    public bool $buttonsDisabled = true;

    public array $items = [];

    public $productos = [];

    public $sucursales = [];

    public array $gastos = [
        ['concepto' => '', 'precio' => 0],
    ];

    public array $mermas = [
        ['concepto' => '', 'precio' => 0],
    ];

    public float $sumExistencia = 0;

    public float $sumEntrada = 0;

    public float $sumSobrante = 0;

    public float $totalSalidas = 0;

    public $efectivoEntregado = 0;

    public $tarjeta = 0;

    // Modal de captura/edición de salidas
    public bool $openSalida = false;

    public ?int $salidaEditId = null;

    public $salidaProductoId = null;

    public $salidaPrecio = null;

    public $salidaCantidad = null;

    public $salidaSucursalDestinoId = null;

    // Modo edición: cuando se llega desde "Editar cuenta" en el detalle/listado
    public ?int $cuentaEditandoId = null;

    // Llenado semi-automático a partir de fotos del formulario en papel
    // (una por cada hoja impresa, ya que la plantilla ocupa varias páginas)
    public array $fotosFormulario = [];

    public bool $procesandoFoto = false;

    public array $camposAutodetectados = [];

    public array $renglonesNoReconocidos = [];

    public bool $iaCapturaHabilitada = false;

    public function mount(?Cuenta $cuenta = null): void
    {
        $this->esAdmin = Auth::user()->hasRole('Admin');
        $this->sucursalId = $this->esAdmin ? null : Auth::user()->sucursal_id;
        $this->iaCapturaHabilitada = Configuracion::activa(Configuracion::IA_CAPTURA_HABILITADA, default: true);

        $this->fechaVenta = now()->format('Y-m-d');
        $this->fechaCaptura = now()->format('Y-m-d');

        $this->productos = Producto::orderBy('name')->get();
        $this->sucursales = Sucursal::activas()
            ->when(! $this->esAdmin, fn ($q) => $q->where('id', '!=', $this->sucursalId))
            ->orderBy('name')
            ->get();

        $this->buttonsDisabled = true;

        if ($cuenta && $this->esAdmin) {
            $this->cargarCuentaExistente($cuenta);
        }
    }

    private function cargarCuentaExistente(Cuenta $cuenta): void
    {
        $this->cuentaEditandoId = $cuenta->id;
        $this->sucursalId = $cuenta->sucursal_id;
        $this->fechaVenta = Carbon::parse($cuenta->fecha_venta)->toDateString();
        $this->fechaCaptura = Carbon::parse($cuenta->fecha_captura)->toDateString();
        $this->efectivoEntregado = $cuenta->efectivo_entregado;
        $this->tarjeta = $cuenta->tarjeta;

        $fechaVentaAnterior = Carbon::parse($this->fechaVenta)->subDay()->toDateString();

        $existenciaAnterior = ItemCuenta::query()
            ->whereHas('cuenta', fn ($q) => $q
                ->where('sucursal_id', $this->sucursalId)
                ->where('fecha_venta', $fechaVentaAnterior))
            ->get()
            ->keyBy('producto_id');

        $itemsActuales = ItemCuenta::where('cuenta_id', $cuenta->id)->get()->keyBy('producto_id');

        $this->items = Producto::orderBy('name')->get()
            ->map(function (Producto $producto) use ($itemsActuales, $existenciaAnterior) {
                $actual = $itemsActuales->get($producto->id);
                $anterior = $existenciaAnterior->get($producto->id);

                return [
                    'producto_id' => $producto->id,
                    'producto' => $producto->name,
                    'categoria_id' => $producto->categoria_id,
                    'precio' => $this->formatearNumero($actual?->precio ?? $anterior?->precio ?? 0),
                    'cantidad_existencia' => $this->formatearNumero($anterior?->cantidad_sobrante ?? 0),
                    'importe_existencia' => $this->formatearNumero($anterior?->importe_sobrante ?? 0),
                    'cantidad_entrada' => $this->formatearNumero($actual?->cantidad_entrada ?? 0),
                    'importe_entrada' => $this->formatearNumero($actual?->importe_entrada ?? 0),
                    'cantidad_salida' => 0.0,
                    'importe_salida' => 0.0,
                    'cantidad_sobrante' => $this->formatearNumero($actual?->cantidad_sobrante ?? 0),
                    'importe_sobrante' => $this->formatearNumero($actual?->importe_sobrante ?? 0),
                ];
            })
            ->values()
            ->toArray();

        $this->sumExistencia = collect($this->items)->sum('importe_existencia');
        $this->sumEntrada = collect($this->items)->sum('importe_entrada');
        $this->sumSobrante = collect($this->items)->sum('importe_sobrante');
        $this->recalcularSalidas();

        $gastosExistentes = Gasto::where('cuenta_id', $cuenta->id)->get();
        $this->gastos = $gastosExistentes->isNotEmpty()
            ? $gastosExistentes->map(fn ($g) => ['concepto' => $g->concepto, 'precio' => (float) $g->precio])->values()->toArray()
            : [['concepto' => '', 'precio' => 0]];

        $mermasExistentes = Merma::where('cuenta_id', $cuenta->id)->get();
        $this->mermas = $mermasExistentes->isNotEmpty()
            ? $mermasExistentes->map(fn ($m) => ['concepto' => $m->concepto, 'precio' => (float) $m->precio])->values()->toArray()
            : [['concepto' => '', 'precio' => 0]];

        $this->buttonsDisabled = false;
        $this->maxStepReached = 7;
    }

    private function formatearNumero($valor): float
    {
        return round((float) $valor, 3);
    }

    // Para montos que solo se muestran (no inputs): sin decimales si es un
    // entero, o con exactamente 2 decimales si no lo es.
    public function formatearImporte($valor): string
    {
        $valor = (float) $valor;

        return fmod($valor, 1.0) === 0.0
            ? number_format($valor, 0)
            : number_format($valor, 2);
    }

    private function extractValues(Producto $producto): array
    {
        $item = $producto->itemsCuenta->first();

        return [
            'producto_id' => $producto->id,
            'producto' => $producto->name,
            'categoria_id' => $producto->categoria_id,
            'precio' => $this->formatearNumero($item?->precio ?? 0),
            'cantidad_existencia' => $this->formatearNumero($item?->cantidad_sobrante ?? 0),
            'importe_existencia' => $this->formatearNumero($item?->importe_sobrante ?? 0),
            'cantidad_entrada' => 0.0,
            'importe_entrada' => 0.0,
            'cantidad_salida' => 0.0,
            'importe_salida' => 0.0,
            'cantidad_sobrante' => 0.0,
            'importe_sobrante' => 0.0,
        ];
    }

    public function presentar(): void
    {
        if ($this->esAdmin) {
            if (empty($this->sucursalId)) {
                Flux::toast(variant: 'danger', text: 'Selecciona una sucursal.');

                return;
            }

            if (empty($this->fechaVenta)) {
                Flux::toast(variant: 'danger', text: 'Selecciona una fecha de venta.');

                return;
            }

            $this->cargarExistencia();
            $this->buttonsDisabled = false;

            return;
        }

        $haEjecutadoHoy = Execution::where('function_name', 'presentar')
            ->where('user_id', Auth::id())
            ->whereDate('last_executed_at', Carbon::today())
            ->exists();

        if ($haEjecutadoHoy) {
            Flux::toast(variant: 'warning', text: 'Solo se puede hacer un registro por día.');

            return;
        }

        $this->cargarExistencia();
        $this->buttonsDisabled = false;
    }

    public function aplicarNuevosPrecios(): void
    {
        $preciosMaestros = Producto::query()->whereNotNull('precio')->pluck('precio', 'id');

        if ($preciosMaestros->isEmpty()) {
            Flux::toast(variant: 'warning', text: 'No hay precios definidos en Productos.');

            return;
        }

        $this->items = collect($this->items)->map(function ($item) use ($preciosMaestros) {
            if ($preciosMaestros->has($item['producto_id'])) {
                $precio = (float) $preciosMaestros[$item['producto_id']];
                $item['precio'] = $this->formatearNumero($precio);
                $item['importe_existencia'] = $this->formatearNumero($precio * (float) $item['cantidad_existencia']);
            }

            return $item;
        })->toArray();

        $this->sumExistencia = collect($this->items)->sum('importe_existencia');

        Flux::toast(variant: 'success', text: 'Precios nuevos aplicados.');
    }

    public function eliminarFotoFormulario(int $index): void
    {
        unset($this->fotosFormulario[$index]);
        $this->fotosFormulario = array_values($this->fotosFormulario);
    }

    public function procesarFotoFormulario(): void
    {
        if (! $this->iaCapturaHabilitada) {
            Flux::toast(variant: 'danger', text: 'El llenado automático con IA no está habilitado.');

            return;
        }

        $this->validate([
            'fotosFormulario' => ['required', 'array', 'min:1', 'max:8'],
            'fotosFormulario.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        if ($this->esAdmin && (empty($this->sucursalId) || empty($this->fechaVenta))) {
            Flux::toast(variant: 'danger', text: 'Selecciona sucursal y fecha de venta antes de procesar la foto.');

            return;
        }

        if (empty($this->items)) {
            $this->cargarExistencia();
        }

        $this->procesandoFoto = true;

        try {
            $catalogo = Producto::orderBy('name')->pluck('name', 'id')->toArray();

            $imagenes = collect($this->fotosFormulario)
                ->map(fn ($foto) => ['contenido' => $foto->get(), 'mime_type' => $foto->getMimeType()])
                ->all();

            $datos = (new InterpretarFormularioCuentaAction)($imagenes, $catalogo);

            $this->aplicarDatosDetectados($datos);
        } catch (\Throwable $e) {
            logger()->error('Error al interpretar foto de cuenta', ['error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', text: 'No se pudo procesar la foto: '.$e->getMessage());

            return;
        } finally {
            $this->procesandoFoto = false;
            $this->fotosFormulario = [];
        }

        $this->buttonsDisabled = false;
    }

    private function aplicarDatosDetectados(array $datos): void
    {
        $this->camposAutodetectados = [];
        $this->renglonesNoReconocidos = [];

        $indexPorProducto = [];
        foreach ($this->items as $i => $item) {
            $indexPorProducto[$item['producto_id']] = $i;
        }

        $aplicarCantidad = function (array $renglones, string $campoCantidad, string $campoImporte) use ($indexPorProducto) {
            foreach ($renglones as $renglon) {
                $cantidad = round((float) ($renglon['cantidad'] ?? 0), 3);

                if ($cantidad <= 0) {
                    continue;
                }

                $productoId = $renglon['producto_id'] ?? null;

                if (! $productoId || ! isset($indexPorProducto[$productoId])) {
                    $this->renglonesNoReconocidos[] = ($renglon['nombre_detectado'] ?? '?')." ({$cantidad})";

                    continue;
                }

                $index = $indexPorProducto[$productoId];
                $precio = (float) $this->items[$index]['precio'];

                $this->items[$index][$campoCantidad] = $cantidad;
                $this->items[$index][$campoImporte] = $this->formatearNumero($precio * $cantidad);
                $this->camposAutodetectados["items.{$index}.{$campoCantidad}"] = true;
            }
        };

        $aplicarCantidad($datos['entradas'] ?? [], 'cantidad_entrada', 'importe_entrada');
        $aplicarCantidad($datos['sobrantes'] ?? [], 'cantidad_sobrante', 'importe_sobrante');

        $this->sumEntrada = collect($this->items)->sum('importe_entrada');
        $this->sumSobrante = collect($this->items)->sum('importe_sobrante');

        $gastosDetectados = collect($datos['gastos'] ?? [])
            ->filter(fn ($g) => ! empty($g['concepto']) && (float) ($g['precio'] ?? 0) > 0)
            ->map(fn ($g) => ['concepto' => $g['concepto'], 'precio' => round((float) $g['precio'], 2)])
            ->values()
            ->toArray();

        if (! empty($gastosDetectados)) {
            $this->gastos = $gastosDetectados;
            foreach (array_keys($gastosDetectados) as $i) {
                $this->camposAutodetectados["gastos.{$i}"] = true;
            }
        }

        $mermasDetectadas = collect($datos['mermas'] ?? [])
            ->filter(fn ($m) => ! empty($m['concepto']) && (float) ($m['precio'] ?? 0) > 0)
            ->map(fn ($m) => ['concepto' => $m['concepto'], 'precio' => round((float) $m['precio'], 2)])
            ->values()
            ->toArray();

        if (! empty($mermasDetectadas)) {
            $this->mermas = $mermasDetectadas;
            foreach (array_keys($mermasDetectadas) as $i) {
                $this->camposAutodetectados["mermas.{$i}"] = true;
            }
        }

        if (! empty($datos['efectivo_entregado'])) {
            $this->efectivoEntregado = round((float) $datos['efectivo_entregado'], 3);
            $this->camposAutodetectados['efectivoEntregado'] = true;
        }

        if (! empty($datos['tarjeta'])) {
            $this->tarjeta = round((float) $datos['tarjeta'], 3);
            $this->camposAutodetectados['tarjeta'] = true;
        }

        // Las salidas necesitan una sucursal destino, dato que la hoja no
        // captura, así que solo se avisan; no se guardan automáticamente.
        $salidasDetectadas = collect($datos['salidas'] ?? [])->filter(fn ($s) => (float) ($s['cantidad'] ?? 0) > 0);

        $resumen = [];
        $totalCampos = collect($this->camposAutodetectados)->count();
        if ($totalCampos > 0) {
            $resumen[] = "{$totalCampos} campo(s) detectados";
        }
        if ($salidasDetectadas->isNotEmpty()) {
            $resumen[] = $salidasDetectadas->count().' salida(s) detectada(s): agrégalas manualmente con su sucursal destino';
        }
        if (! empty($this->renglonesNoReconocidos)) {
            $resumen[] = count($this->renglonesNoReconocidos).' renglón(es) no identificados, revísalos abajo';
        }

        Flux::toast(
            variant: $totalCampos > 0 ? 'success' : 'warning',
            text: $resumen ? 'Foto procesada: '.implode(' · ', $resumen) : 'No se detectaron datos en la foto.',
        );
    }

    private function cargarExistencia(): void
    {
        $fechaVentaAnterior = Carbon::parse($this->fechaVenta)->subDay()->toDateString();

        $this->items = Producto::query()
            ->with(['itemsCuenta' => fn ($q) => $q->whereHas('cuenta', fn ($q) => $q
                ->where('sucursal_id', $this->sucursalId)
                ->where('fecha_venta', $fechaVentaAnterior))])
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => $this->extractValues($p))
            ->values()
            ->toArray();

        $this->sumExistencia = collect($this->items)->sum('importe_existencia');
        $this->recalcularSalidas();
    }

    private function recalcularSalidas(): void
    {
        $this->totalSalidas = Salida::where('fecha_salida', $this->fechaVenta)
            ->where('sucursal_origen_id', $this->sucursalId)
            ->sum('total');
    }

    public function updated($property, $value): void
    {
        if (! preg_match('/^items\.(\d+)\.(cantidad_existencia|cantidad_entrada|cantidad_sobrante|precio)$/', $property, $m)) {
            return;
        }

        $index = (int) $m[1];
        $item = $this->items[$index];
        $precio = (float) $item['precio'];

        $this->items[$index]['importe_existencia'] = $this->formatearNumero($precio * (float) $item['cantidad_existencia']);
        $this->items[$index]['importe_entrada'] = $this->formatearNumero($precio * (float) $item['cantidad_entrada']);
        $this->items[$index]['importe_sobrante'] = $this->formatearNumero($precio * (float) $item['cantidad_sobrante']);

        $this->sumExistencia = collect($this->items)->sum('importe_existencia');
        $this->sumEntrada = collect($this->items)->sum('importe_entrada');
        $this->sumSobrante = collect($this->items)->sum('importe_sobrante');
    }

    #[Computed]
    public function salidasCapturadas()
    {
        if (! $this->sucursalId) {
            return collect();
        }

        return Salida::query()
            ->with(['producto', 'sucursalDestino'])
            ->where('fecha_salida', $this->fechaVenta)
            ->where('sucursal_origen_id', $this->sucursalId)
            ->get();
    }

    public function abrirModalSalida(): void
    {
        $this->reset(['salidaEditId', 'salidaProductoId', 'salidaPrecio', 'salidaCantidad', 'salidaSucursalDestinoId']);
        $this->openSalida = true;
    }

    public function editarSalida(int $salidaId): void
    {
        $salida = Salida::where('sucursal_origen_id', $this->sucursalId)->find($salidaId);

        if (! $salida) {
            Flux::toast(variant: 'warning', text: 'Registro no encontrado.');

            return;
        }

        $this->salidaEditId = $salida->id;
        $this->salidaProductoId = $salida->producto_id;
        $this->salidaPrecio = $salida->precio;
        $this->salidaCantidad = $salida->cantidad;
        $this->salidaSucursalDestinoId = $salida->sucursal_destino_id;
        $this->openSalida = true;
    }

    public function guardarSalida(): void
    {
        $this->validate([
            'salidaProductoId' => ['required', 'exists:productos,id'],
            'salidaPrecio' => ['required', 'numeric', 'min:0.01'],
            'salidaCantidad' => ['required', 'numeric', 'min:0.01'],
            'salidaSucursalDestinoId' => ['required', 'exists:sucursales,id'],
        ]);

        try {
            DB::transaction(function () {
                $total = $this->salidaCantidad * $this->salidaPrecio;

                if ($this->salidaEditId) {
                    $salida = Salida::where('sucursal_origen_id', $this->sucursalId)->findOrFail($this->salidaEditId);
                    $salida->update([
                        'producto_id' => $this->salidaProductoId,
                        'precio' => $this->salidaPrecio,
                        'cantidad' => $this->salidaCantidad,
                        'sucursal_destino_id' => $this->salidaSucursalDestinoId,
                        'total' => $total,
                    ]);
                } else {
                    $cuentaOrigen = Cuenta::firstOrCreate([
                        'sucursal_id' => $this->sucursalId,
                        'fecha_venta' => $this->fechaVenta,
                    ], $this->defaultsCuenta());

                    $salida = Salida::create([
                        'producto_id' => $this->salidaProductoId,
                        'fecha_salida' => $this->fechaVenta,
                        'sucursal_origen_id' => $this->sucursalId,
                        'sucursal_destino_id' => $this->salidaSucursalDestinoId,
                        'cuenta_id' => $cuentaOrigen->id,
                        'precio' => $this->salidaPrecio,
                        'cantidad' => $this->salidaCantidad,
                        'total' => $total,
                    ]);

                    $cuentaDestino = Cuenta::firstOrCreate([
                        'sucursal_id' => $this->salidaSucursalDestinoId,
                        'fecha_venta' => $this->fechaVenta,
                    ], $this->defaultsCuenta());

                    Entrada::create([
                        'sucursal_destino_id' => $this->salidaSucursalDestinoId,
                        'sucursal_origen_id' => $this->sucursalId,
                        'precio_envio' => $this->salidaPrecio,
                        'precio' => 0,
                        'cantidad' => $this->salidaCantidad,
                        'salida_id' => $salida->id,
                        'producto_id' => $this->salidaProductoId,
                        'fecha_entrada' => $this->fechaVenta,
                        'cuenta_id' => $cuentaDestino->id,
                        'total' => $total,
                    ]);
                }
            });

            $this->openSalida = false;
            $this->reset(['salidaEditId', 'salidaProductoId', 'salidaPrecio', 'salidaCantidad', 'salidaSucursalDestinoId']);
            unset($this->salidasCapturadas);
            $this->recalcularSalidas();

            Flux::toast(variant: 'success', text: 'Salida registrada.');
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: 'Error al guardar la salida.');
        }
    }

    public function eliminarSalida(int $salidaId): void
    {
        try {
            $salida = Salida::where('sucursal_origen_id', $this->sucursalId)->find($salidaId);

            if (! $salida) {
                Flux::toast(variant: 'warning', text: 'Registro no encontrado.');

                return;
            }

            Entrada::where('salida_id', $salida->id)->delete();
            $salida->delete();

            unset($this->salidasCapturadas);
            $this->recalcularSalidas();

            Flux::toast(variant: 'success', text: 'Salida eliminada.');
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: 'No se puede eliminar: está asociada a otros registros.');
        }
    }

    private function defaultsCuenta(): array
    {
        return [
            'efectivo_pollo' => 0,
            'efectivo_marinado' => 0,
            'efectivo_entregado' => 0,
            'tarjeta' => 0,
            'efectivo_total' => 0,
            'diferencia' => 0,
            'sobrante' => 0,
            'total_venta' => 0,
        ];
    }

    public function addGasto(): void
    {
        $this->gastos[] = ['concepto' => '', 'precio' => 0];
    }

    public function removeGasto(int $index): void
    {
        unset($this->gastos[$index]);
        $this->gastos = array_values($this->gastos);
    }

    public function addMerma(): void
    {
        $this->mermas[] = ['concepto' => '', 'precio' => 0];
    }

    public function removeMerma(int $index): void
    {
        unset($this->mermas[$index]);
        $this->mermas = array_values($this->mermas);
    }

    #[Computed]
    public function sumGastos(): float
    {
        return collect($this->gastos)->sum(fn ($g) => (float) ($g['precio'] ?? 0));
    }

    #[Computed]
    public function sumMermas(): float
    {
        return collect($this->mermas)->sum(fn ($m) => (float) ($m['precio'] ?? 0));
    }

    #[Computed]
    public function totalVentaCalculado(): float
    {
        return round(
            (float) $this->sumExistencia
            + (float) $this->sumEntrada
            - (float) $this->sumSobrante
            - (float) $this->totalSalidas
            - $this->sumGastos()
            - $this->sumMermas(),
            3
        );
    }

    #[Computed]
    public function diferenciaCalculada(): float
    {
        return round($this->totalVentaCalculado() - $this->totalCapturado(), 3);
    }

    #[Computed]
    public function totalCapturado(): float
    {
        return round((float) $this->efectivoEntregado + (float) $this->tarjeta, 3);
    }

    public function irAPaso(int $step): void
    {
        if ($step >= 1 && $step <= $this->maxStepReached) {
            $this->step = $step;
        }
    }

    public function siguientePaso(): void
    {
        $this->step = min(7, $this->step + 1);
        $this->maxStepReached = max($this->maxStepReached, $this->step);
    }

    public function pasoAnterior(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function guardar(): void
    {
        $user = Auth::user();
        $totalVenta = $this->totalVentaCalculado();
        $diferencia = $this->diferenciaCalculada();
        $cuentaGuardada = null;

        try {
            DB::transaction(function () use (&$cuentaGuardada, $totalVenta, $diferencia) {
                $cuenta = Cuenta::updateOrCreate([
                    'fecha_venta' => $this->fechaVenta,
                    'sucursal_id' => $this->sucursalId,
                ], [
                    'fecha_captura' => $this->fechaCaptura,
                    'efectivo_marinado' => 0,
                    'efectivo_pollo' => 0,
                    'efectivo_entregado' => (float) $this->efectivoEntregado,
                    'tarjeta' => (float) $this->tarjeta,
                    'efectivo_total' => 0,
                    'diferencia' => $diferencia,
                    'sobrante' => $this->sumSobrante,
                    'total_venta' => $totalVenta,
                ]);

                collect($this->items)->each(function ($item) use ($cuenta) {
                    $attributes = (new ProcesarItemAction)($item);
                    ItemCuenta::updateOrCreate([
                        'producto_id' => $attributes['producto_id'],
                        'cuenta_id' => $cuenta->id,
                        'fecha_venta' => $this->fechaVenta,
                    ], $attributes);
                });

                collect($this->gastos)->each(function ($gasto) use ($cuenta) {
                    if (! empty($gasto['precio']) && ! empty($gasto['concepto'])) {
                        Gasto::updateOrCreate([
                            'concepto' => $gasto['concepto'],
                            'sucursal_id' => $this->sucursalId,
                            'cuenta_id' => $cuenta->id,
                        ], [
                            'precio' => $gasto['precio'],
                        ]);
                    }
                });

                collect($this->mermas)->each(function ($merma) use ($cuenta) {
                    if (! empty($merma['precio']) && ! empty($merma['concepto'])) {
                        Merma::updateOrCreate([
                            'concepto' => $merma['concepto'],
                            'sucursal_id' => $this->sucursalId,
                            'cuenta_id' => $cuenta->id,
                        ], [
                            'precio' => $merma['precio'],
                        ]);
                    }
                });

                $this->actualizarEfectivoPolloYMarinado();

                $cuentaGuardada = $cuenta;
            });
        } catch (\Throwable $e) {
            logger()->error('Error al guardar cuenta', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            Flux::toast(variant: 'danger', text: 'Error al guardar la cuenta.');

            return;
        }

        if (! $this->esAdmin) {
            Execution::updateOrCreate([
                'function_name' => 'presentar',
                'user_id' => $user->id,
            ], [
                'last_executed_at' => Carbon::now(),
            ]);
        }

        ActivityLog::log(
            ($this->cuentaEditandoId ? 'editó' : 'registró')." una cuenta ({$this->fechaVenta}) en ".($user->sucursal->name ?? Sucursal::find($this->sucursalId)?->name ?? 'una sucursal'),
            $cuentaGuardada,
        );

        Flux::toast(variant: 'success', text: $this->cuentaEditandoId ? 'Cuenta actualizada exitosamente.' : 'Cuenta guardada exitosamente.');

        if ($this->cuentaEditandoId) {
            $this->redirectRoute('admin.cuentas.show', ['cuenta' => $cuentaGuardada->id], navigate: true);

            return;
        }

        $this->redirectRoute($this->esAdmin ? 'admin.registrar.index' : 'capturista.registrar.index', navigate: true);
    }

    private function actualizarEfectivoPolloYMarinado(): void
    {
        $importeSobrantePollo = ItemCuenta::query()
            ->whereDate('fecha_venta', $this->fechaVenta)
            ->whereHas('cuenta', fn ($q) => $q->where('sucursal_id', $this->sucursalId))
            ->whereHas('producto.categoria', fn ($q) => $q->where('id', 1))
            ->sum('importe_sobrante');

        $efectivoPollo = abs($importeSobrantePollo);

        Cuenta::updateOrCreate([
            'sucursal_id' => $this->sucursalId,
            'fecha_venta' => $this->fechaVenta,
        ], [
            'efectivo_pollo' => $efectivoPollo,
        ]);

        $importeSobranteMarinado = ItemCuenta::query()
            ->whereDate('fecha_venta', $this->fechaVenta)
            ->whereHas('cuenta', fn ($q) => $q->where('sucursal_id', $this->sucursalId))
            ->whereHas('producto.categoria', fn ($q) => $q->where('id', 2))
            ->sum('importe_sobrante');

        $efectivoMarinado = abs($importeSobranteMarinado);

        Cuenta::updateOrCreate([
            'sucursal_id' => $this->sucursalId,
            'fecha_venta' => $this->fechaVenta,
        ], [
            'efectivo_marinado' => $efectivoMarinado,
            'efectivo_total' => abs($efectivoPollo + $efectivoMarinado),
        ]);
    }
}; ?>

@php
    $stepIcons = [
        1 => 'archive-box',
        2 => 'arrow-down-tray',
        3 => 'arrow-right',
        4 => 'credit-card',
        5 => 'trash',
        6 => 'square-3-stack-3d',
        7 => 'check-circle',
    ];
    $stepTitles = [
        1 => ['Existencia del día anterior', 'Elige sucursal y fecha de venta arriba, luego presiona Presentar para cargar los productos.'],
        2 => ['Entradas', 'Captura cuánto producto entró hoy a la sucursal.'],
        3 => ['Salidas', 'Registra los productos enviados a otras sucursales.'],
        4 => ['Gastos', 'Agrega los gastos del día.'],
        5 => ['Merma', 'Agrega la merma del día.'],
        6 => ['Sobrante', 'Captura lo que queda para el día siguiente.'],
        7 => ['Cierre del día', 'Revisa el ticket, compáralo con el efectivo que tienes en caja y guarda la cuenta.'],
    ];
    $sucursalActual = $esAdmin
        ? collect($sucursales)->firstWhere('id', $sucursalId)?->name
        : Auth::user()->sucursal->name ?? null;
@endphp

<div class="grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-6 items-start">
    {{-- RIEL DE NAVEGACIÓN --}}
    <div x-data="{ open: false }" class="bg-white dark:bg-white/10 rounded-xl border border-zinc-200 dark:border-white/10 overflow-hidden lg:sticky lg:top-6">
        <div class="p-5 flex items-start justify-between gap-3">
            <div>
                <div class="text-base font-extrabold text-zinc-900 dark:text-white">ML GRUPO</div>
                <div class="text-xs text-zinc-500">Registro de cuenta diaria</div>
                @if ($cuentaEditandoId)
                    <span class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-accent-soft px-3 py-1 text-[11px] font-bold text-accent">
                        <flux:icon name="pencil-square" class="w-3 h-3" />
                        Editando cuenta existente
                    </span>
                @endif
            </div>
            <button
                type="button"
                x-on:click="open = !open"
                class="lg:hidden flex-none flex items-center gap-1 rounded-lg border border-zinc-200 dark:border-white/10 px-2.5 py-1.5 text-xs font-semibold text-zinc-600 dark:text-zinc-300"
            >
                <span x-text="open ? 'Ocultar' : 'Ver progreso'"></span>
                <flux:icon name="chevron-down" x-bind:class="open ? 'rotate-180' : ''" class="w-3.5 h-3.5 transition-transform" />
            </button>
        </div>

        <div :class="open ? '' : 'hidden lg:block'">
            @if ($sucursalActual)
                <div class="px-5 pb-4">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 dark:bg-white/10 px-3 py-1.5 text-xs font-bold text-zinc-700 dark:text-zinc-200">
                        <flux:icon name="home" class="w-3.5 h-3.5" />
                        {{ $sucursalActual }}
                    </span>
                </div>
            @endif

            <flux:separator />

            <nav class="p-3 space-y-1">
                @foreach ($steps as $n => $label)
                    <button
                        type="button"
                        wire:click="irAPaso({{ $n }})"
                        x-on:click="open = false"
                        @if ($n > $maxStepReached) disabled @endif
                        class="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-sm font-semibold text-start transition
                            {{ $step === $n
                                ? 'bg-accent-soft text-accent'
                                : ($n <= $maxStepReached ? 'text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-white/5' : 'text-zinc-300 dark:text-zinc-600 cursor-not-allowed') }}"
                    >
                        <span class="w-5 h-5 rounded-full flex items-center justify-center text-[11px] font-bold flex-none
                            {{ $step === $n ? 'bg-accent text-white' : 'bg-zinc-100 dark:bg-white/10 text-zinc-400' }}">{{ $n }}</span>
                        <flux:icon :name="$stepIcons[$n]" class="w-4 h-4 flex-none" />
                        <span>{{ $label }}</span>
                    </button>
                @endforeach
            </nav>

            <flux:separator />

            <div class="p-5 bg-zinc-50 dark:bg-white/5">
                <div class="text-[10.5px] font-bold uppercase tracking-wide text-zinc-500 mb-3">Resumen en vivo</div>
                <div class="space-y-1.5 text-sm">
                    <div class="flex justify-between"><span class="text-zinc-500">Existencia</span><span class="font-semibold text-positive">+${{ $this->formatearImporte($sumExistencia) }}</span></div>
                    <div class="flex justify-between"><span class="text-zinc-500">Entrada</span><span class="font-semibold text-positive">+${{ $this->formatearImporte($sumEntrada) }}</span></div>
                    <div class="flex justify-between"><span class="text-zinc-500">Salidas</span><span class="font-semibold text-negative">-${{ $this->formatearImporte($totalSalidas) }}</span></div>
                    <div class="flex justify-between"><span class="text-zinc-500">Gastos</span><span class="font-semibold text-negative">-${{ $this->formatearImporte($this->sumGastos()) }}</span></div>
                    <div class="flex justify-between"><span class="text-zinc-500">Merma</span><span class="font-semibold text-negative">-${{ $this->formatearImporte($this->sumMermas()) }}</span></div>
                    <div class="flex justify-between"><span class="text-zinc-500">Sobrante</span><span class="font-semibold text-negative">-${{ $this->formatearImporte($sumSobrante) }}</span></div>
                </div>
                <flux:separator class="my-3" />
                <div class="flex justify-between items-baseline">
                    <span class="font-bold text-zinc-900 dark:text-white">Total</span>
                    <span class="text-lg font-extrabold text-zinc-900 dark:text-white">${{ $this->formatearImporte($this->totalVentaCalculado()) }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- CONTENIDO --}}
    <div class="bg-white dark:bg-white/10 rounded-xl border border-zinc-200 dark:border-white/10 overflow-hidden">
        {{-- Encabezado: sucursal / fechas --}}
        <div class="bg-zinc-50 dark:bg-white/5 p-5 border-b border-zinc-200 dark:border-white/10">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                @if ($esAdmin)
                    <flux:select wire:model="sucursalId" label="Sucursal" icon="home" field:class="w-full" :disabled="! $buttonsDisabled">
                        <flux:select.option value="">Selecciona una sucursal</flux:select.option>
                        @foreach ($sucursales as $sucursal)
                            <flux:select.option value="{{ $sucursal->id }}">{{ $sucursal->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @else
                    <flux:input label="Sucursal" icon="home" value="{{ Auth::user()->sucursal->name ?? '' }}" readonly field:class="w-full" />
                @endif

                <flux:input type="date" wire:model="fechaVenta" label="Fecha de venta" icon="calendar" field:class="w-full" :disabled="(bool) $cuentaEditandoId" />
                <flux:input type="date" wire:model="fechaCaptura" label="Fecha de captura" icon="calendar" readonly field:class="w-full" />
            </div>
        </div>

        <div class="p-5 space-y-4">
            {{-- Título del paso --}}
            <div class="flex items-start gap-2.5">
                <span class="w-9 h-9 rounded-lg bg-accent-soft text-accent flex items-center justify-center flex-none">
                    <flux:icon :name="$stepIcons[$step]" class="w-4.5 h-4.5" />
                </span>
                <div>
                    <flux:heading size="lg">{{ $stepTitles[$step][0] }}</flux:heading>
                    <flux:subheading>{{ $stepTitles[$step][1] }}</flux:subheading>
                </div>
            </div>

            {{-- Paso 1: Existencia --}}
            @if ($step === 1)
                <div x-data="{ q: '' }" class="space-y-4">
                    @unless ($cuentaEditandoId || ! $iaCapturaHabilitada)
                        <div class="rounded-xl border border-dashed border-accent/40 bg-accent-soft/40 p-4 space-y-3">
                            <div class="flex items-center gap-2">
                                <flux:icon name="camera" class="w-4.5 h-4.5 text-accent" />
                                <span class="font-semibold text-sm">Llenado automático con foto o PDF (IA)</span>
                            </div>
                            <p class="text-xs text-zinc-500">
                                Sube una foto por cada hoja del formulario en papel (puedes elegir varias a la vez), o un solo PDF si ya lo escaneaste; el sistema intentará detectar entradas, sobrante, gastos, merma y efectivo. Siempre revisa los datos antes de guardar.
                            </p>
                            <div class="flex flex-col sm:flex-row gap-2 items-start">
                                <label
                                    for="fotoFormularioInput"
                                    class="inline-flex items-center gap-2 cursor-pointer select-none rounded-lg border border-zinc-300 dark:border-white/20 bg-white dark:bg-white/10 px-3 py-2 text-sm font-medium text-zinc-700 dark:text-zinc-200 hover:bg-zinc-50 dark:hover:bg-white/20 transition"
                                >
                                    <flux:icon name="arrow-up-tray" class="w-4 h-4" />
                                    <span>{{ count($fotosFormulario) ? 'Cambiar archivos' : 'Elegir fotos o PDF' }}</span>
                                </label>
                                <input id="fotoFormularioInput" type="file" wire:model="fotosFormulario" accept="image/*,application/pdf,.pdf" multiple class="hidden">

                                <flux:button size="sm" icon="sparkles" wire:click="procesarFotoFormulario" :disabled="$procesandoFoto || ! count($fotosFormulario)">
                                    {{ $procesandoFoto ? 'Procesando…' : 'Procesar con IA' }}
                                </flux:button>
                            </div>
                            @error('fotosFormulario') <span class="text-xs text-negative">{{ $message }}</span> @enderror
                            @error('fotosFormulario.*') <span class="text-xs text-negative">{{ $message }}</span> @enderror
                            @if (count($fotosFormulario))
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($fotosFormulario as $index => $foto)
                                        <div wire:key="foto-{{ $index }}" class="inline-flex items-center gap-2 text-xs text-positive bg-positive-soft border border-positive/30 rounded-lg pl-3 pr-1.5 py-1.5 w-fit">
                                            <flux:icon name="check-circle" class="w-4 h-4 flex-none" />
                                            <span>{{ $foto->getClientOriginalName() }} cargado</span>
                                            <button
                                                type="button"
                                                wire:click="eliminarFotoFormulario({{ $index }})"
                                                class="cursor-pointer rounded-full p-0.5 text-positive/70 hover:text-negative hover:bg-negative-soft transition"
                                                title="Quitar esta foto"
                                            >
                                                <flux:icon name="x-mark" class="w-3.5 h-3.5" />
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                            @if (! empty($renglonesNoReconocidos))
                                <div class="text-xs text-violet-700 bg-violet-50 border border-violet-200 rounded-lg p-2">
                                    <strong>No identificados, agrégalos manualmente:</strong> {{ implode(', ', $renglonesNoReconocidos) }}
                                </div>
                            @endif
                        </div>
                    @endunless

                    <div class="flex flex-col sm:flex-row gap-2">
                        @unless ($cuentaEditandoId)
                            <flux:button wire:click="presentar" variant="primary">Presentar existencia</flux:button>
                        @endunless
                        <flux:button wire:click="aplicarNuevosPrecios" icon="currency-dollar">Aplicar nuevos precios</flux:button>
                        <flux:input x-model="q" icon="magnifying-glass" placeholder="Buscar producto..." class="sm:ms-auto sm:max-w-xs" />
                    </div>

                    <div class="w-full overflow-x-auto max-h-[28rem] overflow-y-auto">
                        <flux:table class="min-w-[640px]">
                            <flux:table.columns>
                                <flux:table.column>Producto</flux:table.column>
                                <flux:table.column>Precio</flux:table.column>
                                <flux:table.column>Cantidad anterior</flux:table.column>
                                <flux:table.column>Importe anterior</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @forelse ($items as $index => $item)
                                    <flux:table.row
                                        wire:key="item-{{ $item['producto_id'] }}"
                                        data-search="{{ \Illuminate\Support\Str::lower($item['producto']) }}"
                                        x-show="q === '' || $el.dataset.search.includes(q.toLowerCase())"
                                    >
                                        <flux:table.cell>{{ $item['producto'] }}</flux:table.cell>
                                        <flux:table.cell><flux:input size="sm" type="number" step="0.001" icon="currency-dollar" wire:model.live.debounce.500ms="items.{{ $index }}.precio" /></flux:table.cell>
                                        <flux:table.cell>{{ $this->formatearImporte($item['cantidad_existencia']) }} Kg</flux:table.cell>
                                        <flux:table.cell>${{ $this->formatearImporte($item['importe_existencia']) }}</flux:table.cell>
                                    </flux:table.row>
                                @empty
                                    <flux:table.row>
                                        <flux:table.cell colspan="4" class="text-center text-zinc-500 py-10">No hay datos para mostrar</flux:table.cell>
                                    </flux:table.row>
                                @endforelse
                            </flux:table.rows>
                        </flux:table>
                    </div>
                </div>
                @if (! empty($items))
                    <div class="flex justify-between font-semibold">
                        <span>Total existencia</span>
                        <span>${{ $this->formatearImporte($sumExistencia) }}</span>
                    </div>
                @endif
            @endif

            @if ($buttonsDisabled)
                <flux:badge color="zinc">Presenta la existencia para continuar</flux:badge>
            @else
                {{-- Paso 2: Entradas --}}
                @if ($step === 2)
                    <div x-data="{ q: '' }" class="space-y-3">
                        <flux:input x-model="q" icon="magnifying-glass" placeholder="Buscar producto..." class="sm:max-w-xs" />
                        <div class="overflow-x-auto max-h-[28rem] overflow-y-auto">
                            <flux:table class="min-w-[640px]">
                                <flux:table.columns>
                                    <flux:table.column>Producto</flux:table.column>
                                    <flux:table.column>Precio</flux:table.column>
                                    <flux:table.column>Cantidad entrada</flux:table.column>
                                    <flux:table.column>Importe</flux:table.column>
                                </flux:table.columns>
                                <flux:table.rows>
                                    @foreach ($items as $index => $item)
                                        <flux:table.row
                                            wire:key="item-{{ $item['producto_id'] }}"
                                            data-search="{{ \Illuminate\Support\Str::lower($item['producto']) }}"
                                            x-show="q === '' || $el.dataset.search.includes(q.toLowerCase())"
                                        >
                                            <flux:table.cell>{{ $item['producto'] }}</flux:table.cell>
                                            <flux:table.cell>${{ $this->formatearImporte($item['precio']) }}</flux:table.cell>
                                            <flux:table.cell>
                                                <div class="flex items-center gap-1.5">
                                                    <flux:input size="sm" type="number" step="0.001" wire:model.live.debounce.500ms="items.{{ $index }}.cantidad_entrada" />
                                                    @if (isset($camposAutodetectados['items.'.$index.'.cantidad_entrada']))
                                                        <flux:icon name="sparkles" class="w-3.5 h-3.5 text-violet-500 flex-none" title="Detectado automáticamente" />
                                                    @endif
                                                </div>
                                            </flux:table.cell>
                                            <flux:table.cell>${{ $this->formatearImporte($item['importe_entrada']) }}</flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </div>
                    </div>
                    <div class="flex justify-between font-semibold">
                        <span>Total entradas</span>
                        <span>${{ $this->formatearImporte($sumEntrada) }}</span>
                    </div>
                @endif

                {{-- Paso 3: Salidas --}}
                @if ($step === 3)
                    <div class="flex items-center justify-end">
                        <flux:button size="sm" icon="plus" wire:click="abrirModalSalida">Agregar salida</flux:button>
                    </div>
                    <div class="overflow-x-auto">
                        <flux:table class="min-w-[760px]">
                            <flux:table.columns>
                                <flux:table.column>Producto</flux:table.column>
                                <flux:table.column>Destino</flux:table.column>
                                <flux:table.column>Precio</flux:table.column>
                                <flux:table.column>Cantidad</flux:table.column>
                                <flux:table.column>Total</flux:table.column>
                                <flux:table.column>Acciones</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach ($this->salidasCapturadas as $salida)
                                    <flux:table.row wire:key="salida-{{ $salida->id }}">
                                        <flux:table.cell>{{ $salida->producto->name }}</flux:table.cell>
                                        <flux:table.cell>{{ $salida->sucursalDestino->name }}</flux:table.cell>
                                        <flux:table.cell>${{ $this->formatearImporte($salida->precio) }}</flux:table.cell>
                                        <flux:table.cell>{{ $salida->cantidad }}</flux:table.cell>
                                        <flux:table.cell>${{ $this->formatearImporte($salida->total) }}</flux:table.cell>
                                        <flux:table.cell>
                                            <flux:button size="sm" variant="ghost" wire:click="editarSalida({{ $salida->id }})">Editar</flux:button>
                                            <flux:button size="sm" variant="ghost" wire:click="eliminarSalida({{ $salida->id }})" wire:confirm="¿Eliminar esta salida?">Eliminar</flux:button>
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                    <div class="flex justify-between font-semibold">
                        <span>Total salidas</span>
                        <span>${{ $this->formatearImporte($totalSalidas) }}</span>
                    </div>
                @endif

                {{-- Paso 4: Gastos --}}
                @if ($step === 4)
                    <div class="flex items-center justify-end">
                        <flux:button size="sm" icon="plus" wire:click="addGasto">Agregar gasto</flux:button>
                    </div>
                    <div class="space-y-2">
                        @foreach ($gastos as $index => $gasto)
                            <div class="flex flex-col sm:flex-row gap-2 sm:items-end" wire:key="gasto-{{ $index }}">
                                <flux:input field:class="flex-1" size="sm" placeholder="Concepto" wire:model.live.debounce.500ms="gastos.{{ $index }}.concepto" />
                                <div class="flex gap-2 items-end">
                                    <flux:input field:class="flex-1 sm:w-40" size="sm" type="number" step="0.001" icon="currency-dollar" placeholder="Precio" wire:model.live.debounce.500ms="gastos.{{ $index }}.precio" />
                                    @if (isset($camposAutodetectados['gastos.'.$index]))
                                        <flux:icon name="sparkles" class="w-3.5 h-3.5 text-violet-500 flex-none mb-2.5" title="Detectado automáticamente" />
                                    @endif
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeGasto({{ $index }})" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="flex justify-between font-semibold">
                        <span>Total gastos</span>
                        <span>${{ $this->formatearImporte($this->sumGastos()) }}</span>
                    </div>
                @endif

                {{-- Paso 5: Merma --}}
                @if ($step === 5)
                    <div class="flex items-center justify-end">
                        <flux:button size="sm" icon="plus" wire:click="addMerma">Agregar merma</flux:button>
                    </div>
                    <div class="space-y-2">
                        @foreach ($mermas as $index => $merma)
                            <div class="flex flex-col sm:flex-row gap-2 sm:items-end" wire:key="merma-{{ $index }}">
                                <flux:input field:class="flex-1" size="sm" placeholder="Concepto" wire:model.live.debounce.500ms="mermas.{{ $index }}.concepto" />
                                <div class="flex gap-2 items-end">
                                    <flux:input field:class="flex-1 sm:w-40" size="sm" type="number" step="0.001" icon="currency-dollar" placeholder="Precio" wire:model.live.debounce.500ms="mermas.{{ $index }}.precio" />
                                    @if (isset($camposAutodetectados['mermas.'.$index]))
                                        <flux:icon name="sparkles" class="w-3.5 h-3.5 text-violet-500 flex-none mb-2.5" title="Detectado automáticamente" />
                                    @endif
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeMerma({{ $index }})" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="flex justify-between font-semibold">
                        <span>Total merma</span>
                        <span>${{ $this->formatearImporte($this->sumMermas()) }}</span>
                    </div>
                @endif

                {{-- Paso 6: Sobrante --}}
                @if ($step === 6)
                    <div x-data="{ q: '' }" class="space-y-3">
                        <flux:input x-model="q" icon="magnifying-glass" placeholder="Buscar producto..." class="sm:max-w-xs" />
                        <div class="overflow-x-auto max-h-[28rem] overflow-y-auto">
                            <flux:table class="min-w-[640px]">
                                <flux:table.columns>
                                    <flux:table.column>Producto</flux:table.column>
                                    <flux:table.column>Precio</flux:table.column>
                                    <flux:table.column>Cantidad sobrante</flux:table.column>
                                    <flux:table.column>Importe</flux:table.column>
                                </flux:table.columns>
                                <flux:table.rows>
                                    @foreach ($items as $index => $item)
                                        <flux:table.row
                                            wire:key="item-{{ $item['producto_id'] }}"
                                            data-search="{{ \Illuminate\Support\Str::lower($item['producto']) }}"
                                            x-show="q === '' || $el.dataset.search.includes(q.toLowerCase())"
                                        >
                                            <flux:table.cell>{{ $item['producto'] }}</flux:table.cell>
                                            <flux:table.cell>${{ $this->formatearImporte($item['precio']) }}</flux:table.cell>
                                            <flux:table.cell>
                                                <div class="flex items-center gap-1.5">
                                                    <flux:input size="sm" type="number" step="0.001" wire:model.live.debounce.500ms="items.{{ $index }}.cantidad_sobrante" />
                                                    @if (isset($camposAutodetectados['items.'.$index.'.cantidad_sobrante']))
                                                        <flux:icon name="sparkles" class="w-3.5 h-3.5 text-violet-500 flex-none" title="Detectado automáticamente" />
                                                    @endif
                                                </div>
                                            </flux:table.cell>
                                            <flux:table.cell>${{ $this->formatearImporte($item['importe_sobrante']) }}</flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </div>
                    </div>
                    <div class="flex justify-between font-semibold">
                        <span>Total sobrante</span>
                        <span>${{ $this->formatearImporte($sumSobrante) }}</span>
                    </div>
                @endif

                {{-- Paso 7: Cierre del día --}}
                @if ($step === 7)
                    @php $diferencia = $this->diferenciaCalculada(); @endphp

                    <div class="flex items-start gap-2 rounded-xl border border-accent/30 bg-accent-soft p-4 text-sm text-accent">
                        <flux:icon name="information-circle" class="w-5 h-5 flex-none mt-0.5" />
                        <p><strong class="font-bold">Si la diferencia sale en rojo</strong>, hay menos efectivo del que debería haber según lo capturado — revisa gastos y mermas antes de guardar.</p>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        {{-- Ticket de cierre --}}
                        <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-white/5 p-5">
                            <div class="text-center mb-4">
                                <div class="text-[10.5px] font-bold uppercase tracking-wide text-zinc-400">Ticket de cierre</div>
                                <div class="font-bold text-zinc-900 dark:text-white">{{ $sucursalActual }}</div>
                            </div>

                            <div class="divide-y divide-dashed divide-zinc-200 dark:divide-white/10 text-sm">
                                <div class="flex justify-between py-2">
                                    <span class="text-zinc-600 dark:text-zinc-400">Existencia anterior</span>
                                    <span class="font-semibold text-positive">+ ${{ $this->formatearImporte($sumExistencia) }}</span>
                                </div>
                                <div class="flex justify-between py-2">
                                    <span class="text-zinc-600 dark:text-zinc-400">Entradas</span>
                                    <span class="font-semibold text-positive">+ ${{ $this->formatearImporte($sumEntrada) }}</span>
                                </div>
                                <div class="flex justify-between py-2">
                                    <span class="text-zinc-600 dark:text-zinc-400">Salidas</span>
                                    <span class="font-semibold text-negative">- ${{ $this->formatearImporte($totalSalidas) }}</span>
                                </div>
                                <div class="flex justify-between py-2">
                                    <span class="text-zinc-600 dark:text-zinc-400">Gastos</span>
                                    <span class="font-semibold text-negative">- ${{ $this->formatearImporte($this->sumGastos()) }}</span>
                                </div>
                                <div class="flex justify-between py-2">
                                    <span class="text-zinc-600 dark:text-zinc-400">Merma</span>
                                    <span class="font-semibold text-negative">- ${{ $this->formatearImporte($this->sumMermas()) }}</span>
                                </div>
                                <div class="flex justify-between py-2">
                                    <span class="text-zinc-600 dark:text-zinc-400">Sobrante</span>
                                    <span class="font-semibold text-negative">- ${{ $this->formatearImporte($sumSobrante) }}</span>
                                </div>
                            </div>

                            <div class="flex justify-between items-baseline pt-3 mt-1 border-t-2 border-zinc-900 dark:border-white">
                                <span class="font-bold">Total venta</span>
                                <span class="text-xl font-extrabold">${{ $this->formatearImporte($this->totalVentaCalculado()) }}</span>
                            </div>
                        </div>

                        {{-- Efectivo entregado / tarjeta / diferencia --}}
                        <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-white/5 p-5 space-y-4 self-start">
                            <div class="flex items-end gap-1.5">
                                <flux:input field:class="flex-1" type="number" step="0.001" icon="currency-dollar" wire:model.live.debounce.500ms="efectivoEntregado" label="Efectivo entregado" />
                                @if (isset($camposAutodetectados['efectivoEntregado']))
                                    <flux:icon name="sparkles" class="w-4 h-4 text-violet-500 flex-none mb-2.5" title="Detectado automáticamente" />
                                @endif
                            </div>

                            <div class="flex items-end gap-1.5">
                                <flux:input field:class="flex-1" type="number" step="0.001" icon="credit-card" wire:model.live.debounce.500ms="tarjeta" label="Pago con tarjeta" />
                                @if (isset($camposAutodetectados['tarjeta']))
                                    <flux:icon name="sparkles" class="w-4 h-4 text-violet-500 flex-none mb-2.5" title="Detectado automáticamente" />
                                @endif
                            </div>

                            <div class="flex justify-between items-baseline pt-1">
                                <span class="text-[10.5px] font-bold uppercase tracking-wide text-zinc-400">Total capturado</span>
                                <span class="font-bold text-zinc-900 dark:text-white">${{ $this->formatearImporte($this->totalCapturado()) }}</span>
                            </div>

                            <div>
                                <div class="text-[10.5px] font-bold uppercase tracking-wide text-zinc-400 mb-2">Diferencia</div>
                                @if ($diferencia == 0)
                                    <flux:badge color="green" icon="check">Cuadra exacto</flux:badge>
                                @elseif ($diferencia > 0)
                                    <flux:badge color="red" icon="exclamation-triangle">Faltante ${{ $this->formatearImporte($diferencia) }}</flux:badge>
                                @else
                                    <flux:badge color="green" icon="arrow-up">Sobrante ${{ $this->formatearImporte(abs($diferencia)) }}</flux:badge>
                                @endif
                            </div>
                        </div>
                    </div>

                    <flux:button
                        type="button"
                        variant="primary"
                        x-on:click.prevent="
                            Swal.fire({
                                icon: 'question',
                                title: '{{ $cuentaEditandoId ? '¿Estás seguro de actualizar la cuenta?' : '¿Estás seguro de guardar la cuenta?' }}',
                                text: '{{ $cuentaEditandoId ? 'Se sobrescribirán los datos previamente guardados de esta cuenta.' : 'Una vez guardada la cuenta no se podrán realizar cambios.' }}',
                                showDenyButton: true,
                                confirmButtonText: '{{ $cuentaEditandoId ? 'Actualizar' : 'Guardar' }}',
                                denyButtonText: 'Cancelar',
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    Swal.fire({
                                        title: '{{ $cuentaEditandoId ? 'Actualizando cuenta…' : 'Guardando cuenta…' }}',
                                        text: 'Un momento, por favor.',
                                        allowOutsideClick: false,
                                        allowEscapeKey: false,
                                        showConfirmButton: false,
                                        didOpen: () => Swal.showLoading(),
                                    });
                                    $wire.guardar().then(() => Swal.close());
                                }
                            })
                        "
                    >Guardar cuenta</flux:button>
                @endif

                <div class="flex justify-between items-center pt-4 border-t border-zinc-200 dark:border-white/10">
                    <span class="text-xs text-zinc-500">Paso {{ $step }} de 7</span>
                    <div class="flex gap-2">
                        <flux:button variant="ghost" wire:click="pasoAnterior" :disabled="$step === 1">Anterior</flux:button>
                        @if ($step < 7)
                            <flux:button variant="primary" wire:click="siguientePaso">Siguiente</flux:button>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    <flux:modal wire:model="openSalida" name="salida" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $salidaEditId ? 'Editar salida' : 'Nueva salida' }}</flux:heading>

            <flux:select wire:model="salidaProductoId" label="Producto">
                <flux:select.option value="">Selecciona un producto</flux:select.option>
                @foreach ($productos as $producto)
                    <flux:select.option value="{{ $producto->id }}">{{ $producto->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input type="number" step="0.001" wire:model="salidaPrecio" label="Precio" />
            <flux:input type="number" step="0.001" wire:model="salidaCantidad" label="Cantidad" />

            <div class="flex justify-between items-baseline rounded-lg bg-zinc-50 dark:bg-white/5 px-3 py-2.5">
                <span class="text-xs font-bold uppercase tracking-wide text-zinc-400">Total</span>
                <span
                    class="font-bold text-zinc-900 dark:text-white tabular-nums"
                    x-text="'$' + ((Number($wire.salidaPrecio) || 0) * (Number($wire.salidaCantidad) || 0)).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"
                ></span>
            </div>

            <flux:select wire:model="salidaSucursalDestinoId" label="Sucursal destino">
                <flux:select.option value="">Selecciona una sucursal</flux:select.option>
                @foreach ($sucursales as $sucursal)
                    <flux:select.option value="{{ $sucursal->id }}">{{ $sucursal->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('openSalida', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="guardarSalida">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
