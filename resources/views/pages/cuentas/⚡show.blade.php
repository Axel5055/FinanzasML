<?php

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

        $this->recalcularTotales();

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
     * Ver [[fancy-wobbling-frost]]: no se aplica abs() al resultado final para
     * poder reflejar un faltante de caja real cuando los egresos superan
     * la existencia + entrada del día.
     */
    public function recalcularTotales(): void
    {
        $fechaVentaAnterior = Carbon::parse($this->cuenta->fecha_venta)->subDay()->toDateString();

        $totalExistencia = Cuenta::query()
            ->where('fecha_venta', $fechaVentaAnterior)
            ->where('sucursal_id', $this->cuenta->sucursal_id)
            ->value('sobrante') ?? 0;

        $this->totalExistencia = $totalExistencia;

        $totalEntrada = ItemCuenta::where('cuenta_id', $this->cuenta->id)->sum('importe_entrada');
        $totalSobrante = $this->cuenta->sobrante;
        $totalSalidas = Salida::where('cuenta_id', $this->cuenta->id)->sum('total');
        $totalGastos = Gasto::where('cuenta_id', $this->cuenta->id)->sum('precio');
        $totalMermas = Merma::where('cuenta_id', $this->cuenta->id)->sum('precio');
        $totalCapturado = (float) $this->cuenta->efectivo_entregado + (float) $this->cuenta->tarjeta;

        $totalVenta = abs($totalExistencia + $totalEntrada) - $totalSobrante - $totalSalidas - $totalGastos - $totalMermas;
        $diferencia = $totalVenta - $totalCapturado;

        $this->cuenta->update([
            'total_venta' => $totalVenta,
            'diferencia' => $diferencia,
        ]);

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

    // ---- Status ----

    public function guardarStatus(): void
    {
        try {
            $this->cuenta->update(['status_cuenta_id' => $this->statusId]);
            $this->reset(['openStatus', 'statusId']);
            Flux::toast(variant: 'success', text: 'Status actualizado correctamente.');
        } catch (\Throwable $e) {
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
        try {
            DB::transaction(function () {
                $totalVenta = Cuenta::find($this->cuenta->id)->total_venta;
                $totalCapturado = (float) $this->efectivoEntregado + (float) $this->tarjeta;
                $this->cuenta->update([
                    'efectivo_entregado' => $this->efectivoEntregado,
                    'tarjeta' => $this->tarjeta,
                    'diferencia' => $totalVenta - $totalCapturado,
                ]);
            });
            $this->reset(['openEfectivo', 'efectivoEntregado', 'tarjeta']);
            Flux::toast(variant: 'success', text: 'Efectivo y tarjeta actualizados correctamente.');
        } catch (\Throwable $e) {
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

            Entrada::where('salida_id', $salidaId)->delete();
            $salida->delete();

            $this->recalcularTotales();
            $this->cuenta->load('salidas.producto', 'salidas.sucursalDestino');

            Flux::toast(variant: 'success', text: 'Salida eliminada con éxito.');
        } catch (\Throwable $e) {
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
            $this->reset(['openGasto', 'gastoEditId', 'gastoForm']);

            Flux::toast(variant: 'success', text: 'Gasto guardado con éxito.');
        } catch (\Throwable $e) {
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

            Flux::toast(variant: 'success', text: 'Gasto eliminado con éxito.');
        } catch (\Throwable $e) {
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
            $this->reset(['openMerma', 'mermaEditId', 'mermaForm']);

            Flux::toast(variant: 'success', text: 'Merma guardada con éxito.');
        } catch (\Throwable $e) {
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

            Flux::toast(variant: 'success', text: 'Merma eliminada con éxito.');
        } catch (\Throwable $e) {
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
            $this->cuenta->delete();
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
    q: '',
    searchOnTable(e, tableId) {
        this.q = e.target.value.toLowerCase();
        const rows = document.querySelectorAll(`#${tableId} tbody tr[data-search]`);
        rows.forEach(row => {
            row.style.display = row.dataset.search.includes(this.q) ? '' : 'none';
        });
    },
}">
    @php
        $statusMeta = match ($cuenta->status_cuenta?->name) {
            'PAGADO' => ['tone' => 'positive', 'icon' => 'check'],
            'PAGO PARCIAL' => ['tone' => 'accent', 'icon' => 'exclamation-triangle'],
            'PENDIENTE' => ['tone' => 'negative', 'icon' => 'exclamation-triangle'],
            default => ['tone' => 'accent', 'icon' => 'exclamation-triangle'],
        };
    @endphp

    {{-- ENCABEZADO --}}
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex items-center flex-wrap gap-3 mb-1">
                    <h1 class="text-2xl font-extrabold text-zinc-900 dark:text-zinc-100">{{ $cuenta->sucursal?->name }}</h1>
                    <span
                        @class([
                            'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold cursor-pointer',
                            'bg-negative-soft text-negative' => $statusMeta['tone'] === 'negative',
                            'bg-accent-soft text-accent' => $statusMeta['tone'] === 'accent',
                            'bg-positive-soft text-positive' => $statusMeta['tone'] === 'positive',
                        ])
                        wire:click="$set('openStatus', true)"
                    >
                        <flux:icon :name="$statusMeta['icon']" class="w-3.5 h-3.5" />
                        {{ strtoupper($cuenta->status_cuenta?->name ?? 'SIN STATUS') }}
                    </span>
                </div>
                <div class="flex gap-8 mt-4">
                    <div>
                        <div class="text-[10.5px] font-bold uppercase tracking-wide text-zinc-400">Fecha de venta</div>
                        <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ \Carbon\Carbon::parse($cuenta->fecha_venta)->format('d-m-Y') }}</div>
                    </div>
                    <div>
                        <div class="text-[10.5px] font-bold uppercase tracking-wide text-zinc-400">Fecha de captura</div>
                        <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ \Carbon\Carbon::parse($cuenta->fecha_captura)->format('d-m-Y') }}</div>
                    </div>
                </div>
            </div>

            <div class="flex gap-2 flex-wrap">
                <flux:button variant="ghost" wire:click="$set('openStatus', true)">Cambiar status</flux:button>
                <flux:button variant="ghost" wire:click="abrirEfectivo">Actualizar efectivo</flux:button>
                <flux:button icon="pencil-square" :href="route('admin.registrar.index', ['cuenta' => $cuenta->id])" wire:navigate>Editar cuenta</flux:button>
                <flux:button icon="arrow-down-tray" :href="route('admin.cuentas.pdf', $cuenta)" target="_blank">PDF</flux:button>
                <flux:button
                    type="button"
                    variant="danger"
                    icon="trash"
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
    </div>

    {{-- RESUMEN FINANCIERO --}}
    <div class="grid grid-cols-1 lg:grid-cols-[340px_1fr] gap-6 items-start">
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-5 py-4">
            <div class="text-center mb-2">
                <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Resumen financiero</div>
            </div>
            <div class="divide-y divide-dashed divide-zinc-200 dark:divide-white/10 text-[13px]">
                <div class="flex justify-between items-baseline py-[7px]">
                    <span class="text-zinc-900 dark:text-zinc-100">Existencia</span>
                    <span class="tabular-nums font-semibold text-positive">+ ${{ $this->formatearImporte($totalExistencia) }}</span>
                </div>
                <div class="flex justify-between items-baseline py-[7px]">
                    <span class="text-zinc-900 dark:text-zinc-100">Entradas</span>
                    <span class="tabular-nums font-semibold text-positive">+ ${{ $this->formatearImporte($cuenta->itemsCuenta->sum('importe_entrada')) }}</span>
                </div>
                <div class="flex justify-between items-baseline py-[7px]">
                    <span class="text-zinc-900 dark:text-zinc-100">Salidas</span>
                    <span class="tabular-nums font-semibold text-negative">− ${{ $this->formatearImporte($cuenta->salidas->sum('total')) }}</span>
                </div>
                <div class="flex justify-between items-baseline py-[7px]">
                    <span class="text-zinc-900 dark:text-zinc-100">Gastos</span>
                    <span class="tabular-nums font-semibold text-negative">− ${{ $this->formatearImporte($cuenta->gastos->sum('precio')) }}</span>
                </div>
                <div class="flex justify-between items-baseline py-[7px]">
                    <span class="text-zinc-900 dark:text-zinc-100">Merma</span>
                    <span class="tabular-nums font-semibold text-negative">− ${{ $this->formatearImporte($cuenta->mermas->sum('precio')) }}</span>
                </div>
                <div class="flex justify-between items-baseline py-[7px]">
                    <span class="text-zinc-900 dark:text-zinc-100">Sobrante</span>
                    <span class="tabular-nums font-semibold text-negative">− ${{ $this->formatearImporte($cuenta->sobrante) }}</span>
                </div>
            </div>
            <div class="flex justify-between items-baseline pt-3 mt-1 border-t border-zinc-300 dark:border-zinc-600">
                <span class="font-bold text-[14px] text-zinc-900 dark:text-zinc-100">Total venta</span>
                <span @class(['font-extrabold text-[22px] tabular-nums', 'text-negative' => $cuenta->total_venta < 0, 'text-zinc-900 dark:text-zinc-100' => $cuenta->total_venta >= 0])>
                    {{ $cuenta->total_venta < 0 ? '-' : '' }}${{ number_format(abs($cuenta->total_venta), 2) }}
                </span>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 min-w-0">
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-3.5 cursor-pointer" wire:click="abrirEfectivo">
                <div class="text-[10.5px] font-bold uppercase tracking-wide text-zinc-400 mb-1">Pollo</div>
                <div class="text-lg font-extrabold text-zinc-900 dark:text-zinc-100 tabular-nums">${{ number_format($cuenta->efectivo_pollo, 2) }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-3.5">
                <div class="text-[10.5px] font-bold uppercase tracking-wide text-zinc-400 mb-1">Marinado</div>
                <div class="text-lg font-extrabold text-zinc-900 dark:text-zinc-100 tabular-nums">${{ number_format($cuenta->efectivo_marinado, 2) }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-3.5 cursor-pointer" wire:click="abrirEfectivo">
                <div class="text-[10.5px] font-bold uppercase tracking-wide text-zinc-400 mb-1">Efectivo entregado</div>
                <div class="text-lg font-extrabold text-zinc-900 dark:text-zinc-100 tabular-nums">${{ number_format($cuenta->efectivo_entregado, 2) }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-3.5 cursor-pointer" wire:click="abrirEfectivo">
                <div class="text-[10.5px] font-bold uppercase tracking-wide text-zinc-400 mb-1">Tarjeta</div>
                <div class="text-lg font-extrabold text-zinc-900 dark:text-zinc-100 tabular-nums">${{ number_format($cuenta->tarjeta, 2) }}</div>
            </div>
            <div @class([
                'rounded-lg px-4 py-3.5 border',
                'bg-negative-soft border-negative-soft' => $cuenta->diferencia > 0,
                'bg-positive-soft border-positive-soft' => $cuenta->diferencia <= 0,
            ])>
                <div @class([
                    'text-[10.5px] font-bold uppercase tracking-wide mb-1',
                    'text-negative' => $cuenta->diferencia > 0,
                    'text-positive' => $cuenta->diferencia <= 0,
                ])>Diferencia</div>
                <div @class([
                    'text-lg font-extrabold tabular-nums',
                    'text-negative' => $cuenta->diferencia > 0,
                    'text-positive' => $cuenta->diferencia <= 0,
                ])>${{ number_format($cuenta->diferencia, 2) }}</div>
            </div>
        </div>
    </div>

    {{-- DETALLE --}}
    <div class="flex items-center gap-2">
        <span class="w-[26px] h-[26px] rounded bg-accent-soft text-accent flex items-center justify-center flex-none">
            <flux:icon name="ticket" class="w-3.5 h-3.5" />
        </span>
        <h2 class="text-[15px] font-extrabold text-zinc-900 dark:text-zinc-100">Detalle de la cuenta</h2>
    </div>

    <div class="space-y-8">
        {{-- Entradas --}}
        <section>
            <h3 class="text-[13px] font-bold text-zinc-600 dark:text-zinc-400 uppercase tracking-wide mb-2">Entradas de otra sucursal</h3>
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 overflow-hidden">
                <div class="max-h-96 overflow-y-auto overflow-x-auto">
                    <table class="table-fixed w-full text-[13.5px] text-left min-w-[640px]">
                        <thead>
                            <tr class="sticky top-0 z-10">
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 w-1/3">Producto</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 w-1/3">Sucursal origen</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 text-right w-1/6">Precio</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 text-right w-1/6">Cantidad</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 text-right w-1/6">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($cuenta->entradas as $entrada)
                                <tr wire:key="entrada-{{ $entrada->id }}" class="border-b border-zinc-200 dark:border-zinc-700 last:border-b-0 hover:bg-zinc-50 dark:hover:bg-zinc-700/40">
                                    <td class="px-4 py-2 font-semibold text-zinc-900 dark:text-zinc-100 overflow-hidden text-ellipsis whitespace-nowrap" title="{{ $entrada->producto?->name }}">{{ $entrada->producto?->name }}</td>
                                    <td class="px-4 py-2 text-zinc-900 dark:text-zinc-100 overflow-hidden text-ellipsis whitespace-nowrap" title="{{ $entrada->sucursalOrigen?->name }}">{{ $entrada->sucursalOrigen?->name }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-zinc-900 dark:text-zinc-100 whitespace-nowrap">${{ number_format($entrada->precio_envio, 2) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-zinc-900 dark:text-zinc-100 whitespace-nowrap">{{ $entrada->cantidad }} kg</td>
                                    <td class="px-4 py-2 text-right tabular-nums font-semibold text-zinc-900 dark:text-zinc-100 whitespace-nowrap">${{ number_format($entrada->total, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td class="text-center text-zinc-400 text-sm py-10" colspan="5">No hay datos para mostrar</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end items-baseline gap-2 px-4 py-2.5 border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900">
                    <span class="text-xs font-bold text-zinc-400 uppercase tracking-wide">Total</span>
                    <span class="text-sm font-bold text-accent tabular-nums">${{ number_format($cuenta->entradas->sum('total'), 2) }}</span>
                </div>
            </div>
        </section>

        {{-- Salidas --}}
        <section>
            <h3 class="text-[13px] font-bold text-zinc-600 dark:text-zinc-400 uppercase tracking-wide mb-2">Salidas a otra sucursal</h3>
            <div class="flex justify-end mb-3">
                <flux:button size="sm" icon="plus" wire:click="abrirSalida">Agregar salida</flux:button>
            </div>
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 overflow-hidden">
                <div class="max-h-96 overflow-y-auto overflow-x-auto">
                    <table class="table-fixed w-full text-[13.5px] text-left min-w-[720px]">
                        <thead>
                            <tr class="sticky top-0 z-10">
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 w-1/3">Producto</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700">Sucursal destino</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 text-right">Precio</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 text-right">Cantidad</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 text-right">Total</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($cuenta->salidas as $salida)
                                <tr wire:key="salida-{{ $salida->id }}" class="border-b border-zinc-200 dark:border-zinc-700 last:border-b-0 hover:bg-zinc-50 dark:hover:bg-zinc-700/40">
                                    <td class="px-4 py-2 font-semibold text-zinc-900 dark:text-zinc-100 overflow-hidden text-ellipsis whitespace-nowrap" title="{{ $salida->producto?->name }}">{{ $salida->producto?->name }}</td>
                                    <td class="px-4 py-2 text-zinc-900 dark:text-zinc-100 overflow-hidden text-ellipsis whitespace-nowrap" title="{{ $salida->sucursalDestino?->name }}">{{ $salida->sucursalDestino?->name }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-zinc-900 dark:text-zinc-100 whitespace-nowrap">${{ number_format($salida->precio, 2) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-zinc-900 dark:text-zinc-100 whitespace-nowrap">{{ $salida->cantidad }} kg</td>
                                    <td class="px-4 py-2 text-right tabular-nums font-semibold text-zinc-900 dark:text-zinc-100 whitespace-nowrap">${{ number_format($salida->total, 2) }}</td>
                                    <td class="px-4 py-2 whitespace-nowrap">
                                        <div class="flex gap-1.5">
                                            <flux:button size="sm" variant="ghost" wire:click="abrirSalida({{ $salida->id }})">Editar</flux:button>
                                            <flux:button size="sm" variant="ghost" class="!text-negative" wire:click="eliminarSalida({{ $salida->id }})" wire:confirm="¿Eliminar esta salida?">Eliminar</flux:button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="text-center text-zinc-400 text-sm py-10" colspan="6">No hay datos para mostrar</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end items-baseline gap-2 px-4 py-2.5 border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900">
                    <span class="text-xs font-bold text-zinc-400 uppercase tracking-wide">Total</span>
                    <span class="text-sm font-bold text-accent tabular-nums">${{ number_format($cuenta->salidas->sum('total'), 2) }}</span>
                </div>
            </div>
        </section>

        {{-- Gastos --}}
        <section>
            <h3 class="text-[13px] font-bold text-zinc-600 dark:text-zinc-400 uppercase tracking-wide mb-2">Gastos</h3>
            <div class="flex justify-end mb-3">
                <flux:button size="sm" icon="plus" wire:click="abrirGasto">Agregar gasto</flux:button>
            </div>
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 overflow-hidden">
                <div class="max-h-96 overflow-y-auto overflow-x-auto">
                    <table class="table-fixed w-full text-[13.5px] text-left min-w-[480px]">
                        <thead>
                            <tr class="sticky top-0 z-10">
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 w-1/2">Concepto</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 text-right">Precio</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($cuenta->gastos as $gasto)
                                <tr wire:key="gasto-{{ $gasto->id }}" class="border-b border-zinc-200 dark:border-zinc-700 last:border-b-0 hover:bg-zinc-50 dark:hover:bg-zinc-700/40">
                                    <td class="px-4 py-2 font-semibold text-zinc-900 dark:text-zinc-100 overflow-hidden text-ellipsis whitespace-nowrap">{{ $gasto->concepto }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-zinc-900 dark:text-zinc-100 whitespace-nowrap">${{ number_format($gasto->precio, 2) }}</td>
                                    <td class="px-4 py-2 whitespace-nowrap">
                                        <div class="flex gap-1.5">
                                            <flux:button size="sm" variant="ghost" wire:click="abrirGasto({{ $gasto->id }})">Editar</flux:button>
                                            <flux:button size="sm" variant="ghost" class="!text-negative" wire:click="eliminarGasto({{ $gasto->id }})" wire:confirm="¿Eliminar este gasto?">Eliminar</flux:button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="text-center text-zinc-400 text-sm py-10" colspan="3">No hay datos para mostrar</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end items-baseline gap-2 px-4 py-2.5 border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900">
                    <span class="text-xs font-bold text-zinc-400 uppercase tracking-wide">Total</span>
                    <span class="text-sm font-bold text-accent tabular-nums">${{ number_format($cuenta->gastos->sum('precio'), 2) }}</span>
                </div>
            </div>
        </section>

        {{-- Mermas --}}
        <section>
            <h3 class="text-[13px] font-bold text-zinc-600 dark:text-zinc-400 uppercase tracking-wide mb-2">Mermas</h3>
            <div class="flex justify-end mb-3">
                <flux:button size="sm" icon="plus" wire:click="abrirMerma">Agregar merma</flux:button>
            </div>
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 overflow-hidden">
                <div class="max-h-96 overflow-y-auto overflow-x-auto">
                    <table class="table-fixed w-full text-[13.5px] text-left min-w-[480px]">
                        <thead>
                            <tr class="sticky top-0 z-10">
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 w-1/2">Concepto</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 text-right">Precio</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($cuenta->mermas as $merma)
                                <tr wire:key="merma-{{ $merma->id }}" class="border-b border-zinc-200 dark:border-zinc-700 last:border-b-0 hover:bg-zinc-50 dark:hover:bg-zinc-700/40">
                                    <td class="px-4 py-2 font-semibold text-zinc-900 dark:text-zinc-100 overflow-hidden text-ellipsis whitespace-nowrap">{{ $merma->concepto }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-zinc-900 dark:text-zinc-100 whitespace-nowrap">${{ number_format($merma->precio, 2) }}</td>
                                    <td class="px-4 py-2 whitespace-nowrap">
                                        <div class="flex gap-1.5">
                                            <flux:button size="sm" variant="ghost" wire:click="abrirMerma({{ $merma->id }})">Editar</flux:button>
                                            <flux:button size="sm" variant="ghost" class="!text-negative" wire:click="eliminarMerma({{ $merma->id }})" wire:confirm="¿Eliminar esta merma?">Eliminar</flux:button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="text-center text-zinc-400 text-sm py-10" colspan="3">No hay datos para mostrar</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end items-baseline gap-2 px-4 py-2.5 border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900">
                    <span class="text-xs font-bold text-zinc-400 uppercase tracking-wide">Total</span>
                    <span class="text-sm font-bold text-accent tabular-nums">${{ number_format($cuenta->mermas->sum('precio'), 2) }}</span>
                </div>
            </div>
        </section>

        {{-- Productos --}}
        <section>
            <div class="flex items-center justify-between flex-wrap gap-3 mb-2">
                <h3 class="text-[13px] font-bold text-zinc-600 dark:text-zinc-400 uppercase tracking-wide">Productos</h3>
            </div>
            <div class="flex justify-between items-center gap-3 mb-3">
                <div class="relative w-full sm:w-96">
                    <flux:icon name="magnifying-glass" class="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-zinc-400" />
                    <input type="text" placeholder="Buscar producto…"
                        class="w-full border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 rounded pl-8 pr-3 py-2 text-[13px] text-zinc-900 dark:text-zinc-100 focus:outline-none focus:border-accent"
                        @input="searchOnTable($event, 'productos-table')" />
                </div>
                <flux:button size="sm" icon="plus" wire:click="abrirItem">Agregar producto</flux:button>
            </div>
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 overflow-hidden">
                <div id="productos-table" class="max-h-96 overflow-y-auto overflow-x-auto">
                    <table class="table-fixed w-full text-[13.5px] text-left min-w-[960px]">
                        <thead>
                            <tr class="sticky top-0 z-10">
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 w-1/6">Producto</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 text-right">Precio</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 text-right">Cant. existencia</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 text-right">Importe existencia</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 text-right">Cant. entrada</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 text-right">Importe entrada</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 text-right">Cant. sobrante</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 text-right">Importe sobrante</th>
                                <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $itemsVisibles = $cuenta->itemsCuenta->filter(fn ($item) => $item->precio != 0 || $item->cantidad_existencia != 0 || $item->importe_existencia != 0 || $item->cantidad_entrada != 0 || $item->importe_entrada != 0 || $item->cantidad_salida != 0 || $item->importe_salida != 0 || $item->cantidad_sobrante != 0 || $item->importe_sobrante != 0); @endphp
                            @forelse ($itemsVisibles as $item)
                                <tr wire:key="item-{{ $item->id }}" data-search="{{ \Illuminate\Support\Str::lower($item->producto?->name) }}" class="border-b border-zinc-200 dark:border-zinc-700 last:border-b-0 hover:bg-zinc-50 dark:hover:bg-zinc-700/40">
                                    <td class="px-4 py-2 font-semibold text-zinc-900 dark:text-zinc-100 overflow-hidden text-ellipsis whitespace-nowrap" title="{{ $item->producto?->name }}">{{ $item->producto?->name }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-zinc-900 dark:text-zinc-100 whitespace-nowrap">${{ $this->formatearImporte($item->precio) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-zinc-900 dark:text-zinc-100 whitespace-nowrap">{{ $this->formatearImporte($item->cantidad_existencia) }} kg</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-zinc-900 dark:text-zinc-100 whitespace-nowrap">${{ $this->formatearImporte($item->importe_existencia) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-zinc-900 dark:text-zinc-100 whitespace-nowrap">{{ $this->formatearImporte($item->cantidad_entrada) }} kg</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-zinc-900 dark:text-zinc-100 whitespace-nowrap">${{ $this->formatearImporte($item->importe_entrada) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-zinc-900 dark:text-zinc-100 whitespace-nowrap">{{ $this->formatearImporte($item->cantidad_sobrante) }} kg</td>
                                    <td class="px-4 py-2 text-right tabular-nums font-semibold text-zinc-900 dark:text-zinc-100 whitespace-nowrap">${{ $this->formatearImporte($item->importe_sobrante) }}</td>
                                    <td class="px-4 py-2 whitespace-nowrap">
                                        <flux:button size="sm" color="amber" variant="ghost" wire:click="abrirItem({{ $item->id }})">Editar</flux:button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="text-center text-zinc-400 text-sm py-10" colspan="9">No hay datos para mostrar</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap justify-end items-baseline gap-x-6 gap-y-1 px-4 py-2.5 border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900 text-sm">
                    <span class="flex items-baseline gap-1.5">
                        <span class="text-xs font-bold text-zinc-400 uppercase tracking-wide">Existencia</span>
                        <span class="font-bold text-accent tabular-nums">${{ number_format($cuenta->itemsCuenta->sum('importe_existencia'), 2) }}</span>
                    </span>
                    <span class="flex items-baseline gap-1.5">
                        <span class="text-xs font-bold text-zinc-400 uppercase tracking-wide">Entrada</span>
                        <span class="font-bold text-accent tabular-nums">${{ number_format($cuenta->itemsCuenta->sum('importe_entrada'), 2) }}</span>
                    </span>
                    <span class="flex items-baseline gap-1.5">
                        <span class="text-xs font-bold text-zinc-400 uppercase tracking-wide">Sobrante</span>
                        <span class="font-bold text-accent tabular-nums">${{ number_format($cuenta->itemsCuenta->sum('importe_sobrante'), 2) }}</span>
                    </span>
                </div>
            </div>
        </section>
    </div>

    {{-- Modal Status --}}
    <flux:modal wire:model="openStatus" name="status" class="max-w-sm">
        <div class="space-y-4">
            <flux:heading size="lg">Cambiar status</flux:heading>
            <flux:select wire:model="statusId" label="Status">
                <flux:select.option value="">Selecciona un status</flux:select.option>
                @foreach ($this->statusOptions as $status)
                    <flux:select.option value="{{ $status->id }}">{{ $status->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('openStatus', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="guardarStatus">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Modal Efectivo --}}
    <flux:modal wire:model="openEfectivo" name="efectivo" class="max-w-sm">
        <div class="space-y-4">
            <flux:heading size="lg">Efectivo y tarjeta</flux:heading>
            <flux:input type="number" step="0.001" icon="currency-dollar" wire:model="efectivoEntregado" label="Efectivo entregado" />
            <flux:input type="number" step="0.001" icon="credit-card" wire:model="tarjeta" label="Pago con tarjeta" />
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('openEfectivo', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="guardarEfectivo">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Modal Salida --}}
    <flux:modal wire:model="openSalida" name="salida-show" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $salidaEditId ? 'Editar salida' : 'Nueva salida' }}</flux:heading>
            <flux:select wire:model="salidaForm.productoId" label="Producto">
                <flux:select.option value="">Selecciona un producto</flux:select.option>
                @foreach ($this->productos as $producto)
                    <flux:select.option value="{{ $producto->id }}">{{ $producto->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input type="number" step="0.001" wire:model="salidaForm.precio" label="Precio" />
            <flux:input type="number" step="0.001" wire:model="salidaForm.cantidad" label="Cantidad" />
            <div class="flex justify-between items-baseline rounded-lg bg-zinc-50 dark:bg-white/5 px-3 py-2.5">
                <span class="text-xs font-bold uppercase tracking-wide text-zinc-400">Total</span>
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
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('openSalida', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="guardarSalida">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Modal Gasto --}}
    <flux:modal wire:model="openGasto" name="gasto-show" class="max-w-sm">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $gastoEditId ? 'Editar gasto' : 'Nuevo gasto' }}</flux:heading>
            <flux:input wire:model="gastoForm.concepto" label="Concepto" />
            <flux:input type="number" step="0.001" wire:model="gastoForm.precio" label="Precio" />
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('openGasto', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="guardarGasto">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Modal Merma --}}
    <flux:modal wire:model="openMerma" name="merma-show" class="max-w-sm">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $mermaEditId ? 'Editar merma' : 'Nueva merma' }}</flux:heading>
            <flux:input wire:model="mermaForm.concepto" label="Concepto" />
            <flux:input type="number" step="0.001" wire:model="mermaForm.precio" label="Precio" />
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('openMerma', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="guardarMerma">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Modal Item --}}
    <flux:modal wire:model="openItem" name="item-show" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $itemEditId ? 'Editar producto' : 'Agregar producto' }}</flux:heading>
            <flux:select wire:model="itemForm.productoId" label="Producto" :disabled="(bool) $itemEditId">
                <flux:select.option value="">Selecciona un producto</flux:select.option>
                @foreach ($this->productos as $producto)
                    <flux:select.option value="{{ $producto->id }}">{{ $producto->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input type="number" step="0.001" wire:model="itemForm.precio" label="Precio" />
            <flux:input type="number" step="0.001" wire:model="itemForm.cantidadEntrada" label="Cantidad entrada" />
            <flux:input type="number" step="0.001" wire:model="itemForm.cantidadSobrante" label="Cantidad sobrante" />
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('openItem', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="guardarItem">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
