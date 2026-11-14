<?php

use App\Models\ActivityLog;
use App\Models\Cuenta;
use App\Models\Entrada;
use App\Models\Gasto;
use App\Models\ItemCuenta;
use App\Models\Merma;
use App\Models\Producto;
use App\Models\Salida;
use App\Models\StatusCuenta;
use App\Models\Sucursal;
use Carbon\Carbon;
use Domain\Cuentas\Actions\CalcularTotalesCuenta;
use Flux\Flux;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Detalle de cuenta')] class extends Component {
    public Cuenta $cuenta;

    // Status
    public bool $openStatus = false;

    public $statusId;

    // Efectivo entregado
    public bool $openEfectivo = false;

    public $efectivoEntregado;

    public $tarjeta;

    // Salida (add/edit)
    public bool $openSalida = false;

    public ?int $salidaEditId = null;

    public array $salidaForm = ['productoId' => '', 'precio' => '', 'cantidad' => '', 'sucursalDestinoId' => ''];

    // Gasto (add/edit)
    public bool $openGasto = false;

    public ?int $gastoEditId = null;

    public array $gastoForm = ['concepto' => '', 'precio' => ''];

    // Merma (add/edit)
    public bool $openMerma = false;

    public ?int $mermaEditId = null;

    public array $mermaForm = ['concepto' => '', 'precio' => ''];

    // Item (add/edit)
    public bool $openItem = false;

    public ?int $itemEditId = null;

    public array $itemForm = ['productoId' => '', 'precio' => '', 'cantidadEntrada' => '', 'cantidadSobrante' => ''];

    public $totalExistencia = 0;

    public function mount(Cuenta $cuenta): void
    {
        $this->cuenta = $cuenta;

        $this->recalcularTotales(guardar: false);

        $this->cuenta->load([
            'itemsCuenta.producto',
            'gastos',
            'mermas',
            'salidas.producto',
            'salidas.sucursalDestino',
            'sucursal',
            'entradas.producto',
            'entradas.sucursalOrigen',
            'status_cuenta',
        ]);
    }

    #[Computed]
    public function statusOptions()
    {
        return StatusCuenta::all();
    }

    #[Computed]
    public function productos()
    {
        return Producto::query()->select(['id', 'name'])->orderBy('name')->get();
    }

    #[Computed]
    public function sucursales()
    {
        return Sucursal::orderBy('name')->get();
    }


    /**
     * Misma fórmula que la captura (`CalcularTotalesCuenta`). La existencia y el sobrante salen de los
     * renglones guardados de esta cuenta, así que editar aquí nunca cambia el criterio con que se capturó.
     * Sin $guardar solo se calcula la existencia para mostrarla.
     */
    public function recalcularTotales(bool $guardar = true): void
    {
        $this->totalExistencia = ItemCuenta::where('cuenta_id', $this->cuenta->id)->sum('importe_existencia');

        // Solo consultar la cuenta no debe modificarla: los totales se guardan cuando alguien edita algo.
        if (! $guardar) {
            return;
        }

        $totales = (new CalcularTotalesCuenta)(
            (float) $this->totalExistencia,
            (float) ItemCuenta::where('cuenta_id', $this->cuenta->id)->sum('importe_entrada'),
            (float) ItemCuenta::where('cuenta_id', $this->cuenta->id)->sum('importe_sobrante'),
            (float) Salida::where('cuenta_id', $this->cuenta->id)->sum('total'),
            (float) Gasto::where('cuenta_id', $this->cuenta->id)->sum('precio'),
            (float) Merma::where('cuenta_id', $this->cuenta->id)->sum('precio'),
            (float) $this->cuenta->efectivo_entregado,
            (float) $this->cuenta->tarjeta,
        );

        $this->cuenta->update($totales);

        $this->cuenta->refresh();
    }

    public function formatearImporte($valor): string
    {
        $valor = (float) $valor;

        return fmod($valor, 1.0) === 0.0
            ? number_format($valor, 0)
            : number_format($valor, 3);
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

    private function bitacora(string $accion): void
    {
        $fecha = Carbon::parse($this->cuenta->fecha_venta)->format('d/m/Y');
        $sucursal = $this->cuenta->sucursal?->name ?? 'una sucursal';

        ActivityLog::log("{$accion} en la cuenta del {$fecha} ({$sucursal})", $this->cuenta);
    }

    // ---- Status ----

    public function guardarStatus(): void
    {
        $this->validate(['statusId' => ['required', 'exists:status_cuentas,id']]);

        try {
            $this->cuenta->update(['status_cuenta_id' => $this->statusId]);
            $this->bitacora('cambió el status a "'.($this->cuenta->fresh('status_cuenta')->status_cuenta?->name ?? '—').'"');
            $this->reset(['openStatus', 'statusId']);
            Flux::toast(variant: 'success', text: 'Status actualizado correctamente.');
        } catch (\Throwable $e) {
            logger()->error(__METHOD__, ['error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', text: 'Error al cambiar status.');
        }
    }

    // ---- Efectivo entregado / tarjeta ----

    public function abrirEfectivo(): void
    {
        $this->efectivoEntregado = $this->cuenta->efectivo_entregado;
        $this->tarjeta = $this->cuenta->tarjeta;
        $this->openEfectivo = true;
    }

    public function guardarEfectivo(): void
    {
        $this->validate([
            'efectivoEntregado' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'tarjeta' => ['required', 'numeric', 'min:0', 'max:999999999'],
        ]);

        try {
            DB::transaction(function () {
                $this->cuenta->update([
                    'efectivo_entregado' => $this->efectivoEntregado,
                    'tarjeta' => $this->tarjeta,
                ]);

                // Recalcula total y diferencia con lo capturado, sin depender de un total guardado desactualizado.
                $this->recalcularTotales();
            });
            $this->bitacora('actualizó el efectivo entregado y la tarjeta');
            $this->reset(['openEfectivo', 'efectivoEntregado', 'tarjeta']);
            Flux::toast(variant: 'success', text: 'Efectivo y tarjeta actualizados correctamente.');
        } catch (\Throwable $e) {
            logger()->error(__METHOD__, ['error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', text: 'Error al cambiar efectivo.');
        }
    }

    // ---- Salida ----

    public function abrirSalida(?int $salidaId = null): void
    {
        $this->salidaEditId = $salidaId;
        $this->salidaForm = ['productoId' => '', 'precio' => '', 'cantidad' => '', 'sucursalDestinoId' => ''];

        if ($salidaId) {
            $salida = Salida::where('cuenta_id', $this->cuenta->id)->find($salidaId);

            if (! $salida) {
                Flux::toast(variant: 'warning', text: 'Registro no encontrado.');

                return;
            }

            $this->salidaForm = [
                'productoId' => $salida->producto_id,
                'precio' => $salida->precio,
                'cantidad' => $salida->cantidad,
                'sucursalDestinoId' => $salida->sucursal_destino_id,
            ];
        }

        $this->openSalida = true;
    }

    public function guardarSalida(): void
    {
        $this->validate([
            'salidaForm.productoId' => ['required', 'exists:productos,id'],
            'salidaForm.precio' => ['required', 'numeric', 'min:0.01'],
            'salidaForm.cantidad' => ['required', 'numeric', 'min:0.01'],
            'salidaForm.sucursalDestinoId' => ['required', 'exists:sucursales,id'],
        ]);

        try {
            $precio = (float) $this->salidaForm['precio'];
            $cantidad = (float) $this->salidaForm['cantidad'];
            $total = $precio * $cantidad;
            $sucursalDestinoId = $this->salidaForm['sucursalDestinoId'];

            if ($this->salidaEditId) {
                $salida = Salida::where('cuenta_id', $this->cuenta->id)->findOrFail($this->salidaEditId);
                $entrada = Entrada::where('salida_id', $salida->id)->first();

                $cuentaDestino = $sucursalDestinoId == $this->cuenta->sucursal_id
                    ? $this->cuenta
                    : Cuenta::firstOrCreate(['sucursal_id' => $sucursalDestinoId, 'fecha_venta' => $this->cuenta->fecha_venta], $this->defaultsCuenta());

                $salida->update([
                    'producto_id' => $this->salidaForm['productoId'],
                    'precio' => $precio,
                    'cantidad' => $cantidad,
                    'sucursal_destino_id' => $sucursalDestinoId,
                    'total' => $total,
                    'fecha_salida' => $this->cuenta->fecha_venta,
                ]);

                $entradaAttrs = [
                    'producto_id' => $this->salidaForm['productoId'],
                    'precio_envio' => $precio,
                    'cantidad' => $cantidad,
                    'sucursal_destino_id' => $sucursalDestinoId,
                    'sucursal_origen_id' => $this->cuenta->sucursal_id,
                    'fecha_entrada' => $this->cuenta->fecha_venta,
                    'cuenta_id' => $cuentaDestino->id,
                    'total' => $total,
                ];

                $entrada ? $entrada->update($entradaAttrs) : Entrada::create([...$entradaAttrs, 'salida_id' => $salida->id]);
            } else {
                $salida = Salida::create([
                    'precio' => $precio,
                    'cantidad' => $cantidad,
                    'total' => $total,
                    'fecha_salida' => $this->cuenta->fecha_venta,
                    'producto_id' => $this->salidaForm['productoId'],
                    'cuenta_id' => $this->cuenta->id,
                    'sucursal_destino_id' => $sucursalDestinoId,
                    'sucursal_origen_id' => $this->cuenta->sucursal_id,
                ]);

                $cuentaDestino = Cuenta::firstOrCreate(['sucursal_id' => $sucursalDestinoId, 'fecha_venta' => $this->cuenta->fecha_venta], $this->defaultsCuenta());

                Entrada::create([
                    'sucursal_destino_id' => $sucursalDestinoId,
                    'sucursal_origen_id' => $this->cuenta->sucursal_id,
                    'precio_envio' => $precio,
                    'precio' => 0,
                    'cantidad' => $cantidad,
                    'salida_id' => $salida->id,
                    'producto_id' => $this->salidaForm['productoId'],
                    'fecha_entrada' => $this->cuenta->fecha_venta,
                    'cuenta_id' => $cuentaDestino->id,
                    'total' => $total,
                ]);
            }

            $this->recalcularTotales();
            $this->cuenta->load('salidas.producto', 'salidas.sucursalDestino');
            $this->bitacora(($this->salidaEditId ? 'editó' : 'registró').' una salida de '.number_format($cantidad, 2).' de "'.($salida->producto?->name ?? 'producto').'"');
            $this->reset(['openSalida', 'salidaEditId', 'salidaForm']);

            Flux::toast(variant: 'success', text: 'Salida guardada con éxito.');
        } catch (\Throwable $e) {
            logger()->error('Error al guardar salida', ['error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', text: 'Error al guardar la salida.');
        }
    }

    public function eliminarSalida(int $salidaId): void
    {
        try {
            $salida = Salida::where('cuenta_id', $this->cuenta->id)->find($salidaId);

            if (! $salida) {
                Flux::toast(variant: 'warning', text: 'Registro no encontrado.');

                return;
            }

            $nombreProducto = $salida->producto?->name ?? 'producto';

            Entrada::where('salida_id', $salidaId)->delete();
            $salida->delete();

            $this->recalcularTotales();
            $this->cuenta->load('salidas.producto', 'salidas.sucursalDestino');
            $this->bitacora('eliminó una salida de "'.$nombreProducto.'"');

            Flux::toast(variant: 'success', text: 'Salida eliminada con éxito.');
        } catch (\Throwable $e) {
            logger()->error(__METHOD__, ['error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', text: 'No se puede eliminar: está asociada a otros registros.');
        }
    }

    // ---- Gasto ----

    public function abrirGasto(?int $gastoId = null): void
    {
        $this->gastoEditId = $gastoId;
        $this->gastoForm = ['concepto' => '', 'precio' => ''];

        if ($gastoId) {
            $gasto = Gasto::where('cuenta_id', $this->cuenta->id)->find($gastoId);

            if (! $gasto) {
                Flux::toast(variant: 'warning', text: 'Registro no encontrado.');

                return;
            }

            $this->gastoForm = ['concepto' => $gasto->concepto, 'precio' => $gasto->precio];
        }

        $this->openGasto = true;
    }

    public function guardarGasto(): void
    {
        $this->validate([
            'gastoForm.concepto' => ['required', 'string', 'max:255'],
            'gastoForm.precio' => ['required', 'numeric', 'min:0', 'max:999999999'],
        ]);

        $this->gastoForm = ['concepto' => $this->gastoForm['concepto'], 'precio' => $this->gastoForm['precio']];

        try {
            if ($this->gastoEditId) {
                $gasto = Gasto::where('cuenta_id', $this->cuenta->id)->findOrFail($this->gastoEditId);
                $gasto->update($this->gastoForm);
            } else {
                Gasto::updateOrCreate(
                    ['concepto' => $this->gastoForm['concepto'], 'sucursal_id' => $this->cuenta->sucursal_id, 'cuenta_id' => $this->cuenta->id],
                    ['precio' => $this->gastoForm['precio']]
                );
            }

            $this->recalcularTotales();
            $this->cuenta->load('gastos');
            $this->bitacora(($this->gastoEditId ? 'editó' : 'registró').' el gasto "'.($this->gastoForm['concepto'] ?? '').'" por $'.number_format((float) ($this->gastoForm['precio'] ?? 0), 2));
            $this->reset(['openGasto', 'gastoEditId', 'gastoForm']);

            Flux::toast(variant: 'success', text: 'Gasto guardado con éxito.');
        } catch (\Throwable $e) {
            logger()->error(__METHOD__, ['error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', text: 'Error al guardar el gasto.');
        }
    }

    public function eliminarGasto(int $gastoId): void
    {
        try {
            $gasto = Gasto::where('cuenta_id', $this->cuenta->id)->find($gastoId);

            if (! $gasto) {
                Flux::toast(variant: 'warning', text: 'Registro no encontrado.');

                return;
            }

            $gasto->delete();
            $this->recalcularTotales();
            $this->cuenta->load('gastos');
            $this->bitacora('eliminó el gasto "'.$gasto->concepto.'"');

            Flux::toast(variant: 'success', text: 'Gasto eliminado con éxito.');
        } catch (\Throwable $e) {
            logger()->error(__METHOD__, ['error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', text: 'No se puede eliminar el registro.');
        }
    }

    // ---- Merma ----

    public function abrirMerma(?int $mermaId = null): void
    {
        $this->mermaEditId = $mermaId;
        $this->mermaForm = ['concepto' => '', 'precio' => ''];

        if ($mermaId) {
            $merma = Merma::where('cuenta_id', $this->cuenta->id)->find($mermaId);

            if (! $merma) {
                Flux::toast(variant: 'warning', text: 'Registro no encontrado.');

                return;
            }

            $this->mermaForm = ['concepto' => $merma->concepto, 'precio' => $merma->precio];
        }

        $this->openMerma = true;
    }

    public function guardarMerma(): void
    {
        $this->validate([
            'mermaForm.concepto' => ['required', 'string', 'max:255'],
            'mermaForm.precio' => ['required', 'numeric', 'min:0', 'max:999999999'],
        ]);

        $this->mermaForm = ['concepto' => $this->mermaForm['concepto'], 'precio' => $this->mermaForm['precio']];

        try {
            if ($this->mermaEditId) {
                $merma = Merma::where('cuenta_id', $this->cuenta->id)->findOrFail($this->mermaEditId);
                $merma->update($this->mermaForm);
            } else {
                Merma::updateOrCreate(
                    ['concepto' => $this->mermaForm['concepto'], 'sucursal_id' => $this->cuenta->sucursal_id, 'cuenta_id' => $this->cuenta->id],
                    ['precio' => $this->mermaForm['precio']]
                );
            }

            $this->recalcularTotales();
            $this->cuenta->load('mermas');
            $this->bitacora(($this->mermaEditId ? 'editó' : 'registró').' la merma "'.($this->mermaForm['concepto'] ?? '').'" por $'.number_format((float) ($this->mermaForm['precio'] ?? 0), 2));
            $this->reset(['openMerma', 'mermaEditId', 'mermaForm']);

            Flux::toast(variant: 'success', text: 'Merma guardada con éxito.');
        } catch (\Throwable $e) {
            logger()->error(__METHOD__, ['error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', text: 'Error al guardar la merma.');
        }
    }

    public function eliminarMerma(int $mermaId): void
    {
        try {
            $merma = Merma::where('cuenta_id', $this->cuenta->id)->find($mermaId);

            if (! $merma) {
                Flux::toast(variant: 'warning', text: 'Registro no encontrado.');

                return;
            }

            $merma->delete();
            $this->recalcularTotales();
            $this->cuenta->load('mermas');
            $this->bitacora('eliminó la merma "'.$merma->concepto.'"');

            Flux::toast(variant: 'success', text: 'Merma eliminada con éxito.');
        } catch (\Throwable $e) {
            logger()->error(__METHOD__, ['error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', text: 'No se puede eliminar el registro.');
        }
    }

    // ---- Item ----

    public function abrirItem(?int $itemId = null): void
    {
        $this->itemEditId = $itemId;
        $this->itemForm = ['productoId' => '', 'precio' => '', 'cantidadEntrada' => '', 'cantidadSobrante' => ''];

        if ($itemId) {
            $item = ItemCuenta::where('cuenta_id', $this->cuenta->id)->find($itemId);

            if (! $item) {
                Flux::toast(variant: 'warning', text: 'Registro no encontrado.');

                return;
            }

            $this->itemForm = [
                'productoId' => $item->producto_id,
                'precio' => $item->precio,
                'cantidadEntrada' => $item->cantidad_entrada,
                'cantidadSobrante' => $item->cantidad_sobrante,
            ];
        }

        $this->openItem = true;
    }

    public function guardarItem(): void
    {
        $this->validate([
            'itemForm.productoId' => ['required', 'exists:productos,id'],
            'itemForm.precio' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'itemForm.cantidadEntrada' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'itemForm.cantidadSobrante' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);

        try {
            $precio = (float) $this->itemForm['precio'];
            $cantidadEntrada = (float) $this->itemForm['cantidadEntrada'];
            $cantidadSobrante = (float) $this->itemForm['cantidadSobrante'];

            if ($this->itemEditId) {
                $item = ItemCuenta::where('cuenta_id', $this->cuenta->id)->findOrFail($this->itemEditId);
                $item->update([
                    'producto_id' => $this->itemForm['productoId'],
                    'precio' => $precio,
                    'cantidad_entrada' => $cantidadEntrada,
                    'importe_entrada' => $precio * $cantidadEntrada,
                    'cantidad_sobrante' => $cantidadSobrante,
                    'importe_sobrante' => $precio * $cantidadSobrante,
                ]);
            } else {
                ItemCuenta::updateOrCreate(
                    ['cuenta_id' => $this->cuenta->id, 'producto_id' => $this->itemForm['productoId']],
                    [
                        'precio' => $precio,
                        'cantidad_entrada' => $cantidadEntrada,
                        'importe_entrada' => $precio * $cantidadEntrada,
                        'cantidad_sobrante' => $cantidadSobrante,
                        'importe_sobrante' => $precio * $cantidadSobrante,
                        'fecha_venta' => $this->cuenta->fecha_venta,
                    ]
                );
            }

            $totalSobrante = ItemCuenta::where('cuenta_id', $this->cuenta->id)->sum('importe_sobrante');
            $this->cuenta->update(['sobrante' => $totalSobrante]);

            $this->recalcularTotales();
            $this->cuenta->load('itemsCuenta.producto');
            $this->bitacora(($this->itemEditId ? 'editó' : 'registró').' el producto "'.(Producto::find($this->itemForm['productoId'])?->name ?? 'producto').'"');
            $this->reset(['openItem', 'itemEditId', 'itemForm']);

            Flux::toast(variant: 'success', text: 'Producto guardado con éxito.');
        } catch (\Throwable $e) {
            logger()->error('Error al guardar item', ['error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', text: 'Error al guardar el producto.');
        }
    }

    // ---- Eliminar cuenta ----

    public function eliminarCuenta()
    {
        try {
            $fecha = Carbon::parse($this->cuenta->fecha_venta)->format('d/m/Y');
            $sucursal = $this->cuenta->sucursal?->name ?? 'una sucursal';

            $this->cuenta->delete();
            ActivityLog::log("eliminó la cuenta del {$fecha} ({$sucursal})", $this->cuenta);
            Flux::toast(variant: 'success', text: 'Cuenta eliminada con éxito.');

            return $this->redirectRoute('admin.cuentas.index', navigate: true);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                Flux::toast(variant: 'warning', text: 'No se puede eliminar la cuenta porque está asociada a otros registros.');

                return;
            }

            throw $e;
        }
    }
}; ?>

<div class="space-y-6" x-data="{
    tab: 'productos',
    q: '',
    searchOnTable(e, listId) {
        this.q = e.target.value.toLowerCase();
        document.querySelectorAll(`#${listId} [data-search]`).forEach(el => {
            el.style.display = el.dataset.search.includes(this.q) ? '' : 'none';
        });
    },
}">
    @php
        $statusMeta = match ($cuenta->status_cuenta?->name) {
            'PAGADO' => ['tone' => 'positive', 'icon' => 'check-circle'],
            'PAGO PARCIAL' => ['tone' => 'accent', 'icon' => 'clock'],
            'PENDIENTE' => ['tone' => 'negative', 'icon' => 'exclamation-triangle'],
            default => ['tone' => 'accent', 'icon' => 'question-mark-circle'],
        };

        $th = 'bg-zinc-50 px-4 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500 border-b border-zinc-200/70 dark:bg-zinc-800 dark:border-zinc-700/70';
        $tr = 'border-b border-zinc-100 last:border-b-0 hover:bg-zinc-50/80 dark:border-zinc-800 dark:hover:bg-white/5';
        $td = 'px-4 py-3 text-zinc-900 dark:text-zinc-100';
        $tarjetaMovil = 'border-b border-zinc-100 px-4 py-3.5 last:border-b-0 dark:border-zinc-800';

        $importeEntradas = (float) $cuenta->itemsCuenta->sum('importe_entrada');
        $totalSalidas = (float) $cuenta->salidas->sum('total');
        $totalGastos = (float) $cuenta->gastos->sum('precio');
        $totalMermas = (float) $cuenta->mermas->sum('precio');
        $totalEntradasOtras = (float) $cuenta->entradas->sum('total');
        $disponible = abs((float) $totalExistencia + $importeEntradas);

        $diferencia = (float) $cuenta->diferencia;
        $hayFaltante = $diferencia > 0.005;
        $haySobrante = $diferencia < -0.005;
        $cobrado = (float) $cuenta->efectivo_entregado + (float) $cuenta->tarjeta;

        $itemsVisibles = $cuenta->itemsCuenta->filter(fn ($item) => $item->precio != 0 || $item->cantidad_existencia != 0 || $item->importe_existencia != 0 || $item->cantidad_entrada != 0 || $item->importe_entrada != 0 || $item->cantidad_salida != 0 || $item->importe_salida != 0 || $item->cantidad_sobrante != 0 || $item->importe_sobrante != 0);

        $pestanas = [
            ['clave' => 'productos', 'etiqueta' => 'Productos', 'icono' => 'cube', 'total' => $itemsVisibles->count()],
            ['clave' => 'entradas', 'etiqueta' => 'Entradas', 'icono' => 'arrow-down-tray', 'total' => $cuenta->entradas->count()],
            ['clave' => 'salidas', 'etiqueta' => 'Salidas', 'icono' => 'arrow-up-tray', 'total' => $cuenta->salidas->count()],
            ['clave' => 'gastos', 'etiqueta' => 'Gastos', 'icono' => 'credit-card', 'total' => $cuenta->gastos->count()],
            ['clave' => 'mermas', 'etiqueta' => 'Mermas', 'icono' => 'trash', 'total' => $cuenta->mermas->count()],
        ];
    @endphp

    {{-- ENCABEZADO --}}
    <div>
        <flux:button size="sm" variant="subtle" icon="arrow-left" :href="route('admin.cuentas.index')" wire:navigate>Cuentas</flux:button>
    </div>

    <div class="rounded-2xl border border-zinc-200/70 bg-white p-5 shadow-sm shadow-zinc-900/5 sm:p-6 dark:border-zinc-700/70 dark:bg-zinc-900 dark:shadow-none">
        <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-4">
            <div class="flex min-w-0 items-center gap-4">
                <span class="flex size-14 flex-none items-center justify-center rounded-2xl bg-accent-soft text-accent-content">
                    <flux:icon name="building-storefront" class="size-7" />
                </span>
                <div class="min-w-0">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Cuenta de venta</div>
                    <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">{{ $cuenta->sucursal?->name }}</h1>
                    <div class="mt-2 flex flex-wrap items-center gap-x-5 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400">
                        <span class="inline-flex items-center gap-1.5">
                            <flux:icon name="calendar-days" class="size-4" />
                            Venta del <strong class="font-semibold text-zinc-900 dark:text-zinc-100">{{ \Carbon\Carbon::parse($cuenta->fecha_venta)->format('d/m/Y') }}</strong>
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <flux:icon name="pencil-square" class="size-4" />
                            Capturada el <strong class="font-semibold text-zinc-900 dark:text-zinc-100">{{ \Carbon\Carbon::parse($cuenta->fecha_captura)->format('d/m/Y') }}</strong>
                        </span>
                    </div>
                </div>
            </div>

            <button
                type="button"
                wire:click="$set('openStatus', true)"
                title="Cambiar status"
                aria-label="Cambiar status"
                @class([
                    'inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold transition hover:brightness-95',
                    'bg-negative-soft text-negative' => $statusMeta['tone'] === 'negative',
                    'bg-accent-soft text-accent-content' => $statusMeta['tone'] === 'accent',
                    'bg-positive-soft text-positive' => $statusMeta['tone'] === 'positive',
                ])
            >
                <flux:icon :name="$statusMeta['icon']" class="size-4" />
                {{ strtoupper($cuenta->status_cuenta?->name ?? 'SIN STATUS') }}
                <flux:icon name="chevron-down" class="size-3.5 opacity-60" />
            </button>
        </div>

        <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
            <flux:button variant="primary" icon="pencil-square" :href="route('admin.registrar.index', ['cuenta' => $cuenta->id])" wire:navigate>Editar cuenta</flux:button>
            <flux:button icon="banknotes" wire:click="abrirEfectivo">Actualizar efectivo</flux:button>
            <flux:button icon="arrow-down-tray" :href="route('admin.cuentas.pdf', $cuenta)" target="_blank">PDF</flux:button>
            <flux:button
                type="button"
                variant="ghost"
                icon="trash"
                class="ms-auto !text-negative"
                x-on:click.prevent="
                    Swal.fire({
                        icon: 'question',
                        title: '¿Estás seguro de eliminar la cuenta?',
                        text: 'Una vez eliminada no se podrá recuperar.',
                        showDenyButton: true,
                        confirmButtonText: 'Eliminar',
                        denyButtonText: 'No eliminar',
                    }).then((result) => {
                        if (result.isConfirmed) {
                            $wire.eliminarCuenta().catch((error) => {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error al eliminar',
                                    text: error.message || 'Ocurrió un problema al intentar eliminar la cuenta.',
                                    showConfirmButton: true,
                                });
                            });
                        }
                    })
                "
            >Eliminar cuenta</flux:button>
        </div>
    </div>

    {{-- RESUMEN --}}
    <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-[22rem_minmax(0,1fr)]">
        <div class="rounded-2xl border border-zinc-200/70 bg-white p-5 shadow-sm shadow-zinc-900/5 dark:border-zinc-700/70 dark:bg-zinc-900 dark:shadow-none">
            <h2 class="text-base font-semibold tracking-tight text-zinc-900 dark:text-white">¿Cómo se calculó la venta?</h2>
            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Producto disponible menos lo que salió o no se vendió.</p>

            <div class="mt-4 space-y-1 text-sm">
                @foreach ([
                    ['Existencia del día anterior', 'archive-box', $totalExistencia, '+'],
                    ['Entradas del día', 'arrow-down-tray', $importeEntradas, '+'],
                ] as [$etiqueta, $icono, $valor, $signo])
                    <div class="flex items-center gap-3 py-1.5">
                        <span class="flex size-8 flex-none items-center justify-center rounded-lg bg-positive/10 text-positive"><flux:icon :name="$icono" class="size-4" /></span>
                        <span class="flex-1 text-zinc-600 dark:text-zinc-300">{{ $etiqueta }}</span>
                        <span class="font-semibold tabular-nums text-positive">+ ${{ $this->formatearImporte($valor) }}</span>
                    </div>
                @endforeach

                <div class="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 dark:bg-white/5">
                    <span class="font-medium text-zinc-700 dark:text-zinc-200">Producto disponible</span>
                    <span class="font-semibold tabular-nums text-zinc-900 dark:text-white">${{ $this->formatearImporte($disponible) }}</span>
                </div>

                @foreach ([
                    ['Salidas a otra sucursal', 'arrow-up-tray', $totalSalidas],
                    ['Gastos', 'credit-card', $totalGastos],
                    ['Mermas', 'trash', $totalMermas],
                    ['Sobrante', 'cube', (float) $cuenta->sobrante],
                ] as [$etiqueta, $icono, $valor])
                    <div class="flex items-center gap-3 py-1.5">
                        <span class="flex size-8 flex-none items-center justify-center rounded-lg bg-negative/10 text-negative"><flux:icon :name="$icono" class="size-4" /></span>
                        <span class="flex-1 text-zinc-600 dark:text-zinc-300">{{ $etiqueta }}</span>
                        <span class="font-semibold tabular-nums text-negative">− ${{ $this->formatearImporte($valor) }}</span>
                    </div>
                @endforeach
            </div>

            <div class="mt-3 flex items-baseline justify-between rounded-xl bg-accent-soft px-4 py-3">
                <span class="text-xs font-semibold uppercase tracking-wider text-accent-content">Total venta</span>
                <span class="text-lg font-bold tabular-nums text-accent-content">{{ $cuenta->total_venta < 0 ? '-' : '' }}${{ number_format(abs($cuenta->total_venta), 2) }}</span>
            </div>
        </div>

        <div class="grid min-w-0 gap-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-stat-card label="Total venta" icon="banknotes" tone="brand" size="lg">
                    {{ $cuenta->total_venta < 0 ? '-' : '' }}${{ number_format(abs($cuenta->total_venta), 2) }}
                    <x-slot:footer>Lo que debió cobrarse en el día</x-slot:footer>
                </x-stat-card>

                <div @class([
                    '@container rounded-2xl border p-4 sm:p-5',
                    'border-negative/20 bg-negative-soft' => $hayFaltante,
                    'border-positive/20 bg-positive-soft' => ! $hayFaltante,
                ])>
                    <div class="flex items-center gap-4">
                        <span @class([
                            'hidden size-12 flex-none items-center justify-center rounded-xl @[12rem]:flex',
                            'bg-negative/15 text-negative' => $hayFaltante,
                            'bg-positive/15 text-positive' => ! $hayFaltante,
                        ])>
                            <flux:icon :name="$hayFaltante ? 'exclamation-triangle' : 'check-circle'" class="size-6" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div @class(['text-[11px] leading-tight font-medium uppercase tracking-wide', 'text-negative' => $hayFaltante, 'text-positive' => ! $hayFaltante])>
                                {{ $hayFaltante ? 'Faltante' : ($haySobrante ? 'Sobrante en caja' : 'Cuenta cuadrada') }}
                            </div>
                            <div @class(['mt-0.5 text-2xl font-bold leading-tight tracking-tight tabular-nums @[15rem]:text-3xl @[19rem]:text-4xl', 'text-negative' => $hayFaltante, 'text-positive' => ! $hayFaltante])>
                                {{ $diferencia < 0 ? '-' : '' }}${{ number_format(abs($diferencia), 2) }}
                            </div>
                            <div class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">
                                Cobrado ${{ number_format($cobrado, 2) }} de ${{ number_format($cuenta->total_venta, 2) }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
                <x-stat-card label="Efectivo entregado" icon="banknotes" class="cursor-pointer" wire:click="abrirEfectivo">
                    ${{ number_format($cuenta->efectivo_entregado, 2) }}
                </x-stat-card>
                <x-stat-card label="Tarjeta" icon="credit-card" class="cursor-pointer" wire:click="abrirEfectivo">
                    ${{ number_format($cuenta->tarjeta, 2) }}
                </x-stat-card>
                <x-stat-card label="Pollo" icon="currency-dollar">
                    ${{ number_format($cuenta->efectivo_pollo, 2) }}
                </x-stat-card>
                <x-stat-card label="Marinado" icon="shopping-bag">
                    ${{ number_format($cuenta->efectivo_marinado, 2) }}
                </x-stat-card>
            </div>

            @php
                $baseCobro = max((float) $cuenta->total_venta, $cobrado, 0.01);
                $pctEfectivo = min(100, max(0, (float) $cuenta->efectivo_entregado / $baseCobro * 100));
                $pctTarjeta = min(100 - $pctEfectivo, max(0, (float) $cuenta->tarjeta / $baseCobro * 100));
                $pctCobrado = (float) $cuenta->total_venta > 0 ? min(100, (int) floor($cobrado / (float) $cuenta->total_venta * 100)) : 0;
            @endphp

            <div class="rounded-2xl border border-zinc-200/70 bg-white p-5 shadow-sm shadow-zinc-900/5 dark:border-zinc-700/70 dark:bg-zinc-900 dark:shadow-none">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-base font-semibold tracking-tight text-zinc-900 dark:text-white">Cobro de la venta</h2>
                    <span class="text-sm text-zinc-500 dark:text-zinc-400">
                        @if ($hayFaltante)
                            Faltan por cobrar <strong class="font-semibold text-negative">${{ number_format($diferencia, 2) }}</strong>
                        @elseif ($haySobrante)
                            Se cobraron <strong class="font-semibold text-positive">${{ number_format(abs($diferencia), 2) }}</strong> de más
                        @else
                            <strong class="font-semibold text-positive">Cobro completo</strong>
                        @endif
                    </span>
                </div>

                <div class="mt-4 flex h-3 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10" role="img" aria-label="Se ha cobrado el {{ $pctCobrado }}% de la venta">
                    <div class="h-full bg-accent" style="width: {{ $pctEfectivo }}%"></div>
                    <div class="h-full bg-accent/45" style="width: {{ $pctTarjeta }}%"></div>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-1.5 text-sm text-zinc-600 dark:text-zinc-300">
                    <span class="inline-flex items-center gap-2"><span class="size-2.5 rounded-full bg-accent"></span>Efectivo ${{ number_format($cuenta->efectivo_entregado, 2) }}</span>
                    <span class="inline-flex items-center gap-2"><span class="size-2.5 rounded-full bg-accent/45"></span>Tarjeta ${{ number_format($cuenta->tarjeta, 2) }}</span>
                    <span class="ms-auto font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $pctCobrado }}% cobrado</span>
                </div>
            </div>
        </div>
    </div>

    {{-- DETALLE --}}
    <x-page-header class="pt-2" icon="clipboard-document-list" title="Detalle de la cuenta" subtitle="Todo lo que se registró en esta cuenta, separado por tipo." />

    <div class="-mx-1 overflow-x-auto px-1 pb-1">
        <div class="inline-flex gap-1 rounded-xl bg-zinc-200/60 p-1 dark:bg-white/10" role="tablist">
            @foreach ($pestanas as $pestana)
                <button
                    type="button"
                    role="tab"
                    x-on:click="tab = '{{ $pestana['clave'] }}'"
                    x-bind:aria-selected="tab === '{{ $pestana['clave'] }}'"
                    x-bind:class="tab === '{{ $pestana['clave'] }}' ? 'bg-white text-accent-content shadow-sm dark:bg-zinc-900' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                    class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg px-3.5 py-1.5 text-sm font-medium transition"
                >
                    <flux:icon :name="$pestana['icono']" class="hidden size-4 sm:block" />
                    {{ $pestana['etiqueta'] }}
                    <span class="rounded-full bg-zinc-900/5 px-1.5 text-[11px] font-semibold tabular-nums dark:bg-white/10">{{ $pestana['total'] }}</span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- Productos --}}
    <div x-show="tab === 'productos'" x-cloak role="tabpanel">
        <x-detalle-panel
            icon="cube"
            title="Productos"
            subtitle="Existencia, entradas y sobrante de cada producto."
            :totales="[
                'Existencia' => '$'.number_format($cuenta->itemsCuenta->sum('importe_existencia'), 2),
                'Entrada' => '$'.number_format($importeEntradas, 2),
                'Sobrante' => '$'.number_format($cuenta->itemsCuenta->sum('importe_sobrante'), 2),
            ]"
        >
            <x-slot:action>
                <flux:button size="sm" icon="plus" wire:click="abrirItem">Agregar producto</flux:button>
            </x-slot:action>

            @if ($itemsVisibles->isEmpty())
                <x-empty-state icon="cube" title="Todavía no hay productos" hint="Agrega los productos con su existencia, entrada y sobrante para calcular la venta." />
            @else
                <div class="border-b border-zinc-100 px-4 py-3 dark:border-zinc-800">
                    <div class="relative w-full sm:max-w-sm">
                        <flux:icon name="magnifying-glass" class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                        <input
                            type="text"
                            placeholder="Buscar producto…"
                            class="w-full rounded-lg border border-zinc-200 bg-white py-2 pl-9 pr-3 text-sm text-zinc-900 focus:border-accent focus:outline-none dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                            x-on:input="searchOnTable($event, 'productos-lista')"
                        />
                    </div>
                </div>

                <div id="productos-lista" class="max-h-[32rem] overflow-y-auto">
                    <table class="hidden w-full text-left text-[13.5px] md:table">
                        <thead>
                            <tr class="sticky top-0 z-10">
                                <th class="{{ $th }}">Producto</th>
                                <th class="{{ $th }} text-right">Precio</th>
                                <th class="{{ $th }} text-right">Existencia</th>
                                <th class="{{ $th }} text-right">Entrada</th>
                                <th class="{{ $th }} text-right">Sobrante</th>
                                <th class="{{ $th }} w-16"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($itemsVisibles as $item)
                                <tr wire:key="item-{{ $item->id }}" data-search="{{ \Illuminate\Support\Str::lower($item->producto?->name) }}" class="{{ $tr }}">
                                    <td class="{{ $td }} font-semibold">{{ $item->producto?->name }}</td>
                                    <td class="{{ $td }} text-right tabular-nums">${{ $this->formatearImporte($item->precio) }}</td>
                                    <td class="{{ $td }} text-right tabular-nums">
                                        <div>{{ $this->formatearImporte($item->cantidad_existencia) }} kg</div>
                                        <div class="text-xs text-zinc-500">${{ $this->formatearImporte($item->importe_existencia) }}</div>
                                    </td>
                                    <td class="{{ $td }} text-right tabular-nums">
                                        <div>{{ $this->formatearImporte($item->cantidad_entrada) }} kg</div>
                                        <div class="text-xs text-zinc-500">${{ $this->formatearImporte($item->importe_entrada) }}</div>
                                    </td>
                                    <td class="{{ $td }} text-right tabular-nums">
                                        <div>{{ $this->formatearImporte($item->cantidad_sobrante) }} kg</div>
                                        <div class="text-xs text-zinc-500">${{ $this->formatearImporte($item->importe_sobrante) }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" aria-label="Editar producto" wire:click="abrirItem({{ $item->id }})" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="md:hidden">
                        @foreach ($itemsVisibles as $item)
                            <div wire:key="item-movil-{{ $item->id }}" data-search="{{ \Illuminate\Support\Str::lower($item->producto?->name) }}" class="{{ $tarjetaMovil }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="truncate font-semibold text-zinc-900 dark:text-white">{{ $item->producto?->name }}</div>
                                        <div class="text-xs text-zinc-500">Precio ${{ $this->formatearImporte($item->precio) }}</div>
                                    </div>
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" aria-label="Editar producto" wire:click="abrirItem({{ $item->id }})" />
                                </div>
                                <div class="mt-2.5 grid grid-cols-3 gap-2 text-center">
                                    @foreach ([
                                        ['Existencia', $item->cantidad_existencia, $item->importe_existencia],
                                        ['Entrada', $item->cantidad_entrada, $item->importe_entrada],
                                        ['Sobrante', $item->cantidad_sobrante, $item->importe_sobrante],
                                    ] as [$etiqueta, $kilos, $importe])
                                        <div class="min-w-0 rounded-lg bg-zinc-50 px-1.5 py-2 dark:bg-white/5">
                                            <div class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500">{{ $etiqueta }}</div>
                                            <div class="mt-0.5 text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $this->formatearImporte($kilos) }} kg</div>
                                            <div class="text-xs tabular-nums text-zinc-500">${{ $this->formatearImporte($importe) }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-detalle-panel>
    </div>

    {{-- Entradas --}}
    <div x-show="tab === 'entradas'" x-cloak role="tabpanel">
        <x-detalle-panel
            icon="arrow-down-tray"
            title="Entradas de otra sucursal"
            subtitle="Producto que llegó desde otras sucursales."
            :totales="$cuenta->entradas->isEmpty() ? [] : ['Total' => '$'.number_format($totalEntradasOtras, 2)]"
        >
            @if ($cuenta->entradas->isEmpty())
                <x-empty-state icon="arrow-down-tray" title="Sin entradas de otras sucursales" hint="Cuando otra sucursal te envíe producto, aparecerá aquí automáticamente." />
            @else
                <div class="max-h-[32rem] overflow-y-auto">
                    <table class="hidden w-full text-left text-[13.5px] md:table">
                        <thead>
                            <tr class="sticky top-0 z-10">
                                <th class="{{ $th }}">Producto</th>
                                <th class="{{ $th }}">Sucursal origen</th>
                                <th class="{{ $th }} text-right">Precio</th>
                                <th class="{{ $th }} text-right">Cantidad</th>
                                <th class="{{ $th }} text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cuenta->entradas as $entrada)
                                <tr wire:key="entrada-{{ $entrada->id }}" class="{{ $tr }}">
                                    <td class="{{ $td }} font-semibold">{{ $entrada->producto?->name }}</td>
                                    <td class="{{ $td }}">{{ $entrada->sucursalOrigen?->name }}</td>
                                    <td class="{{ $td }} text-right tabular-nums">${{ number_format($entrada->precio_envio, 2) }}</td>
                                    <td class="{{ $td }} text-right tabular-nums">{{ $this->formatearImporte($entrada->cantidad) }} kg</td>
                                    <td class="{{ $td }} text-right font-semibold tabular-nums">${{ number_format($entrada->total, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="md:hidden">
                        @foreach ($cuenta->entradas as $entrada)
                            <div wire:key="entrada-movil-{{ $entrada->id }}" class="{{ $tarjetaMovil }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="truncate font-semibold text-zinc-900 dark:text-white">{{ $entrada->producto?->name }}</div>
                                        <div class="text-xs text-zinc-500">Desde {{ $entrada->sucursalOrigen?->name }}</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-semibold tabular-nums text-zinc-900 dark:text-white">${{ number_format($entrada->total, 2) }}</div>
                                        <div class="text-xs tabular-nums text-zinc-500">{{ $this->formatearImporte($entrada->cantidad) }} kg × ${{ number_format($entrada->precio_envio, 2) }}</div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-detalle-panel>
    </div>

    {{-- Salidas --}}
    <div x-show="tab === 'salidas'" x-cloak role="tabpanel">
        <x-detalle-panel
            icon="arrow-up-tray"
            title="Salidas a otra sucursal"
            subtitle="Producto que se envió a otras sucursales."
            :totales="$cuenta->salidas->isEmpty() ? [] : ['Total' => '$'.number_format($totalSalidas, 2)]"
        >
            <x-slot:action>
                <flux:button size="sm" icon="plus" wire:click="abrirSalida">Agregar salida</flux:button>
            </x-slot:action>

            @if ($cuenta->salidas->isEmpty())
                <x-empty-state icon="arrow-up-tray" title="Sin salidas registradas" hint="Registra aquí el producto que enviaste a otra sucursal; se descontará de la venta." />
            @else
                <div class="max-h-[32rem] overflow-y-auto">
                    <table class="hidden w-full text-left text-[13.5px] md:table">
                        <thead>
                            <tr class="sticky top-0 z-10">
                                <th class="{{ $th }}">Producto</th>
                                <th class="{{ $th }}">Sucursal destino</th>
                                <th class="{{ $th }} text-right">Precio</th>
                                <th class="{{ $th }} text-right">Cantidad</th>
                                <th class="{{ $th }} text-right">Total</th>
                                <th class="{{ $th }} w-24"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cuenta->salidas as $salida)
                                <tr wire:key="salida-{{ $salida->id }}" class="{{ $tr }}">
                                    <td class="{{ $td }} font-semibold">{{ $salida->producto?->name }}</td>
                                    <td class="{{ $td }}">{{ $salida->sucursalDestino?->name }}</td>
                                    <td class="{{ $td }} text-right tabular-nums">${{ number_format($salida->precio, 2) }}</td>
                                    <td class="{{ $td }} text-right tabular-nums">{{ $this->formatearImporte($salida->cantidad) }} kg</td>
                                    <td class="{{ $td }} text-right font-semibold tabular-nums">${{ number_format($salida->total, 2) }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex justify-end gap-1">
                                            <flux:button size="sm" variant="ghost" icon="pencil-square" aria-label="Editar salida" wire:click="abrirSalida({{ $salida->id }})" />
                                            <flux:button size="sm" variant="ghost" icon="trash" class="!text-negative" aria-label="Eliminar salida" wire:click="eliminarSalida({{ $salida->id }})" wire:confirm="¿Eliminar esta salida?" />
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="md:hidden">
                        @foreach ($cuenta->salidas as $salida)
                            <div wire:key="salida-movil-{{ $salida->id }}" class="{{ $tarjetaMovil }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="truncate font-semibold text-zinc-900 dark:text-white">{{ $salida->producto?->name }}</div>
                                        <div class="text-xs text-zinc-500">Hacia {{ $salida->sucursalDestino?->name }}</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-semibold tabular-nums text-zinc-900 dark:text-white">${{ number_format($salida->total, 2) }}</div>
                                        <div class="text-xs tabular-nums text-zinc-500">{{ $this->formatearImporte($salida->cantidad) }} kg × ${{ number_format($salida->precio, 2) }}</div>
                                    </div>
                                </div>
                                <div class="mt-2 flex justify-end gap-1">
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="abrirSalida({{ $salida->id }})">Editar</flux:button>
                                    <flux:button size="sm" variant="ghost" icon="trash" class="!text-negative" wire:click="eliminarSalida({{ $salida->id }})" wire:confirm="¿Eliminar esta salida?">Eliminar</flux:button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-detalle-panel>
    </div>

    {{-- Gastos --}}
    <div x-show="tab === 'gastos'" x-cloak role="tabpanel">
        <x-detalle-panel
            icon="credit-card"
            title="Gastos"
            subtitle="Gastos del día descontados de la cuenta."
            :totales="$cuenta->gastos->isEmpty() ? [] : ['Total' => '$'.number_format($totalGastos, 2)]"
        >
            <x-slot:action>
                <flux:button size="sm" icon="plus" wire:click="abrirGasto">Agregar gasto</flux:button>
            </x-slot:action>

            @if ($cuenta->gastos->isEmpty())
                <x-empty-state icon="credit-card" title="Sin gastos registrados" hint="Anota los gastos del día (gas, insumos, etc.) para descontarlos de la venta." />
            @else
                <div class="max-h-[32rem] overflow-y-auto">
                    <table class="hidden w-full text-left text-[13.5px] md:table">
                        <thead>
                            <tr class="sticky top-0 z-10">
                                <th class="{{ $th }}">Concepto</th>
                                <th class="{{ $th }} text-right">Importe</th>
                                <th class="{{ $th }} w-24"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cuenta->gastos as $gasto)
                                <tr wire:key="gasto-{{ $gasto->id }}" class="{{ $tr }}">
                                    <td class="{{ $td }} font-semibold">{{ $gasto->concepto }}</td>
                                    <td class="{{ $td }} text-right font-semibold tabular-nums">${{ number_format($gasto->precio, 2) }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex justify-end gap-1">
                                            <flux:button size="sm" variant="ghost" icon="pencil-square" aria-label="Editar gasto" wire:click="abrirGasto({{ $gasto->id }})" />
                                            <flux:button size="sm" variant="ghost" icon="trash" class="!text-negative" aria-label="Eliminar gasto" wire:click="eliminarGasto({{ $gasto->id }})" wire:confirm="¿Eliminar este gasto?" />
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="md:hidden">
                        @foreach ($cuenta->gastos as $gasto)
                            <div wire:key="gasto-movil-{{ $gasto->id }}" class="{{ $tarjetaMovil }} flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="truncate font-semibold text-zinc-900 dark:text-white">{{ $gasto->concepto }}</div>
                                    <div class="text-sm font-semibold tabular-nums text-zinc-600 dark:text-zinc-300">${{ number_format($gasto->precio, 2) }}</div>
                                </div>
                                <div class="flex gap-1">
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" aria-label="Editar gasto" wire:click="abrirGasto({{ $gasto->id }})" />
                                    <flux:button size="sm" variant="ghost" icon="trash" class="!text-negative" aria-label="Eliminar gasto" wire:click="eliminarGasto({{ $gasto->id }})" wire:confirm="¿Eliminar este gasto?" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-detalle-panel>
    </div>

    {{-- Mermas --}}
    <div x-show="tab === 'mermas'" x-cloak role="tabpanel">
        <x-detalle-panel
            icon="trash"
            title="Mermas"
            subtitle="Producto perdido o dañado durante el día."
            :totales="$cuenta->mermas->isEmpty() ? [] : ['Total' => '$'.number_format($totalMermas, 2)]"
        >
            <x-slot:action>
                <flux:button size="sm" icon="plus" wire:click="abrirMerma">Agregar merma</flux:button>
            </x-slot:action>

            @if ($cuenta->mermas->isEmpty())
                <x-empty-state icon="trash" title="Sin mermas registradas" hint="Registra el producto que se perdió o se dañó para que se descuente de la venta." />
            @else
                <div class="max-h-[32rem] overflow-y-auto">
                    <table class="hidden w-full text-left text-[13.5px] md:table">
                        <thead>
                            <tr class="sticky top-0 z-10">
                                <th class="{{ $th }}">Concepto</th>
                                <th class="{{ $th }} text-right">Importe</th>
                                <th class="{{ $th }} w-24"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cuenta->mermas as $merma)
                                <tr wire:key="merma-{{ $merma->id }}" class="{{ $tr }}">
                                    <td class="{{ $td }} font-semibold">{{ $merma->concepto }}</td>
                                    <td class="{{ $td }} text-right font-semibold tabular-nums">${{ number_format($merma->precio, 2) }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex justify-end gap-1">
                                            <flux:button size="sm" variant="ghost" icon="pencil-square" aria-label="Editar merma" wire:click="abrirMerma({{ $merma->id }})" />
                                            <flux:button size="sm" variant="ghost" icon="trash" class="!text-negative" aria-label="Eliminar merma" wire:click="eliminarMerma({{ $merma->id }})" wire:confirm="¿Eliminar esta merma?" />
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="md:hidden">
                        @foreach ($cuenta->mermas as $merma)
                            <div wire:key="merma-movil-{{ $merma->id }}" class="{{ $tarjetaMovil }} flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="truncate font-semibold text-zinc-900 dark:text-white">{{ $merma->concepto }}</div>
                                    <div class="text-sm font-semibold tabular-nums text-zinc-600 dark:text-zinc-300">${{ number_format($merma->precio, 2) }}</div>
                                </div>
                                <div class="flex gap-1">
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" aria-label="Editar merma" wire:click="abrirMerma({{ $merma->id }})" />
                                    <flux:button size="sm" variant="ghost" icon="trash" class="!text-negative" aria-label="Eliminar merma" wire:click="eliminarMerma({{ $merma->id }})" wire:confirm="¿Eliminar esta merma?" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-detalle-panel>
    </div>

    {{-- Modal Status --}}
    <flux:modal wire:model="openStatus" name="status" class="max-w-sm">
        <div class="space-y-4">
            <x-modal-header icon="flag" title="Cambiar status" subtitle="Actualiza el estado de pago de la cuenta." />
            <flux:select wire:model="statusId" label="Status">
                <flux:select.option value="">Selecciona un status</flux:select.option>
                @foreach ($this->statusOptions as $status)
                    <flux:select.option value="{{ $status->id }}">{{ $status->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="$set('openStatus', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="guardarStatus">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Modal Efectivo --}}
    <flux:modal wire:model="openEfectivo" name="efectivo" class="max-w-sm">
        <div class="space-y-4">
            <x-modal-header icon="banknotes" title="Efectivo y tarjeta" subtitle="Registra lo entregado en efectivo y con tarjeta." />
            <flux:input type="number" step="0.001" icon="currency-dollar" wire:model="efectivoEntregado" label="Efectivo entregado" />
            <flux:input type="number" step="0.001" icon="credit-card" wire:model="tarjeta" label="Pago con tarjeta" />
            <div class="flex justify-end gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="$set('openEfectivo', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="guardarEfectivo">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Modal Salida --}}
    <flux:modal wire:model="openSalida" name="salida-show" class="max-w-md">
        <div class="space-y-4">
            <x-modal-header icon="truck" :title="$salidaEditId ? 'Editar salida' : 'Nueva salida'" subtitle="Producto que se envía a otra sucursal." />
            <flux:select wire:model="salidaForm.productoId" label="Producto">
                <flux:select.option value="">Selecciona un producto</flux:select.option>
                @foreach ($this->productos as $producto)
                    <flux:select.option value="{{ $producto->id }}">{{ $producto->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input type="number" step="0.001" wire:model="salidaForm.precio" label="Precio" />
            <flux:input type="number" step="0.001" wire:model="salidaForm.cantidad" label="Cantidad" />
            <div class="flex items-baseline justify-between rounded-xl bg-accent-soft px-4 py-3">
                <span class="text-xs font-semibold uppercase tracking-wider text-accent-content">Total</span>
                <span
                    class="font-bold text-zinc-900 dark:text-white tabular-nums"
                    x-text="'$' + ((Number($wire.salidaForm.precio) || 0) * (Number($wire.salidaForm.cantidad) || 0)).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"
                ></span>
            </div>
            <flux:select wire:model="salidaForm.sucursalDestinoId" label="Sucursal destino">
                <flux:select.option value="">Selecciona una sucursal</flux:select.option>
                @foreach ($this->sucursales as $sucursal)
                    <flux:select.option value="{{ $sucursal->id }}">{{ $sucursal->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="$set('openSalida', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="guardarSalida">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Modal Gasto --}}
    <flux:modal wire:model="openGasto" name="gasto-show" class="max-w-sm">
        <div class="space-y-4">
            <x-modal-header icon="credit-card" :title="$gastoEditId ? 'Editar gasto' : 'Nuevo gasto'" subtitle="Egreso registrado en esta cuenta." />
            <flux:input wire:model="gastoForm.concepto" label="Concepto" />
            <flux:input type="number" step="0.001" wire:model="gastoForm.precio" label="Precio" />
            <div class="flex justify-end gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="$set('openGasto', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="guardarGasto">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Modal Merma --}}
    <flux:modal wire:model="openMerma" name="merma-show" class="max-w-sm">
        <div class="space-y-4">
            <x-modal-header icon="trash" :title="$mermaEditId ? 'Editar merma' : 'Nueva merma'" subtitle="Producto perdido o dañado en esta cuenta." />
            <flux:input wire:model="mermaForm.concepto" label="Concepto" />
            <flux:input type="number" step="0.001" wire:model="mermaForm.precio" label="Precio" />
            <div class="flex justify-end gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="$set('openMerma', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="guardarMerma">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Modal Item --}}
    <flux:modal wire:model="openItem" name="item-show" class="max-w-md">
        <div class="space-y-4">
            <x-modal-header icon="cube" :title="$itemEditId ? 'Editar producto' : 'Agregar producto'" subtitle="Producto con su precio, entrada y sobrante." />
            <flux:select wire:model="itemForm.productoId" label="Producto" :disabled="(bool) $itemEditId">
                <flux:select.option value="">Selecciona un producto</flux:select.option>
                @foreach ($this->productos as $producto)
                    <flux:select.option value="{{ $producto->id }}">{{ $producto->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input type="number" step="0.001" wire:model="itemForm.precio" label="Precio" />
            <flux:input type="number" step="0.001" wire:model="itemForm.cantidadEntrada" label="Cantidad entrada" />
            <flux:input type="number" step="0.001" wire:model="itemForm.cantidadSobrante" label="Cantidad sobrante" />
            <div class="flex justify-end gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="$set('openItem', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="guardarItem">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
