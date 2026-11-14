<?php

use App\Actions\Reportes\GenerarReporte;
use App\Models\Sucursal;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Reportes')] class extends Component {
    public string $tipo = 'ventas';

    public string $periodo = 'mes';

    public string $desde = '';

    public string $hasta = '';

    public string $sucursalId = '';

    public function mount(): void
    {
        $this->aplicarPeriodo();
    }

    public function seleccionar(string $tipo): void
    {
        if (isset(GenerarReporte::TIPOS[$tipo])) {
            $this->tipo = $tipo;
        }
    }

    public function updatedPeriodo(): void
    {
        $this->aplicarPeriodo();
    }

    public function updatedDesde(): void
    {
        $this->periodo = 'personalizado';
    }

    public function updatedHasta(): void
    {
        $this->periodo = 'personalizado';
    }

    private function aplicarPeriodo(): void
    {
        $hoy = \App\Actions\Fechas\DiaDeNegocio::ahora();

        [$desde, $hasta] = match ($this->periodo) {
            'hoy' => [$hoy->copy(), $hoy->copy()],
            'semana' => [$hoy->copy()->startOfWeek(), $hoy->copy()],
            'mes_anterior' => [$hoy->copy()->subMonthNoOverflow()->startOfMonth(), $hoy->copy()->subMonthNoOverflow()->endOfMonth()],
            'trimestre' => [$hoy->copy()->subMonthsNoOverflow(2)->startOfMonth(), $hoy->copy()],
            'anio' => [$hoy->copy()->startOfYear(), $hoy->copy()],
            'personalizado' => [null, null],
            default => [$hoy->copy()->startOfMonth(), $hoy->copy()],
        };

        if ($desde && $hasta) {
            $this->desde = $desde->toDateString();
            $this->hasta = $hasta->toDateString();
        }
    }

    /**
     * Rango de fechas válido: si faltan o están invertidas se corrige.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function rango(): array
    {
        $parsear = function (string $fecha, CarbonInterface $respaldo): CarbonInterface {
            try {
                return $fecha !== '' ? Carbon::parse($fecha) : $respaldo;
            } catch (\Throwable) {
                return $respaldo;
            }
        };

        $desde = $parsear($this->desde, \App\Actions\Fechas\DiaDeNegocio::ahora()->startOfMonth());
        $hasta = $parsear($this->hasta, \App\Actions\Fechas\DiaDeNegocio::ahora());

        return $desde->gt($hasta) ? [$hasta, $desde] : [$desde, $hasta];
    }

    #[Computed]
    public function reporte(): array
    {
        [$desde, $hasta] = $this->rango();

        return (new GenerarReporte)->ejecutar(
            $this->tipo,
            $desde,
            $hasta,
            $this->sucursalId !== '' ? (int) $this->sucursalId : null,
        );
    }

    #[Computed]
    public function sucursales()
    {
        return Sucursal::orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return array<string, string>
     */
    public function parametrosDescarga(): array
    {
        [$desde, $hasta] = $this->rango();

        return array_filter([
            'tipo' => $this->tipo,
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'sucursal_id' => $this->sucursalId,
        ], fn ($valor) => $valor !== '');
    }
}; ?>

<div class="space-y-6">
    <x-page-header icon="document-chart-bar" title="Reportes" subtitle="Consulta y descarga la información del negocio por periodo y sucursal." />

    {{-- TIPO DE REPORTE --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @foreach (GenerarReporte::TIPOS as $clave => $meta)
            <button
                type="button"
                wire:click="seleccionar('{{ $clave }}')"
                wire:key="tipo-{{ $clave }}"
                @class([
                    'flex items-start gap-3 rounded-2xl border p-4 text-left transition',
                    'border-accent bg-accent-soft/60 ring-1 ring-accent dark:bg-accent/10' => $tipo === $clave,
                    'border-zinc-200/70 bg-white shadow-sm shadow-zinc-900/5 hover:border-accent/50 dark:border-zinc-700/70 dark:bg-zinc-900 dark:shadow-none' => $tipo !== $clave,
                ])
            >
                <span @class([
                    'flex size-10 flex-none items-center justify-center rounded-xl',
                    'bg-accent text-white' => $tipo === $clave,
                    'bg-accent-soft text-accent' => $tipo !== $clave,
                ])>
                    <flux:icon :name="$meta['icono']" class="size-5" />
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-zinc-900 dark:text-white">{{ $meta['titulo'] }}</span>
                    <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">{{ $meta['descripcion'] }}</span>
                </span>
            </button>
        @endforeach
    </div>

    {{-- FILTROS Y DESCARGA --}}
    <div class="space-y-4 rounded-2xl border border-zinc-200/70 bg-white p-4 shadow-sm shadow-zinc-900/5 dark:border-zinc-700/70 dark:bg-zinc-900 dark:shadow-none">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <flux:select wire:model.live="periodo" size="sm" label="Periodo">
                <flux:select.option value="hoy">Hoy</flux:select.option>
                <flux:select.option value="semana">Esta semana</flux:select.option>
                <flux:select.option value="mes">Este mes</flux:select.option>
                <flux:select.option value="mes_anterior">Mes anterior</flux:select.option>
                <flux:select.option value="trimestre">Últimos 3 meses</flux:select.option>
                <flux:select.option value="anio">Este año</flux:select.option>
                <flux:select.option value="personalizado">Personalizado</flux:select.option>
            </flux:select>

            <flux:input wire:model.live="desde" type="date" size="sm" label="Desde" />
            <flux:input wire:model.live="hasta" type="date" size="sm" label="Hasta" />

            <flux:select wire:model.live="sucursalId" size="sm" label="Sucursal">
                <flux:select.option value="">Todas las sucursales</flux:select.option>
                @foreach ($this->sucursales as $sucursal)
                    <flux:select.option value="{{ $sucursal->id }}">{{ $sucursal->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
            <p class="text-sm text-zinc-500">
                {{ $this->reporte['periodo'] }} · {{ $this->reporte['sucursal'] }}
            </p>
            <div class="flex flex-wrap items-center gap-2">
                @if (count($this->reporte['filas']) > GenerarReporte::MAX_FILAS_PDF)
                    <flux:button size="sm" variant="primary" icon="document-arrow-down" disabled data-test="pdf-deshabilitado">Descargar PDF</flux:button>
                    <p class="basis-full text-xs text-amber-600 sm:basis-auto dark:text-amber-400" data-test="aviso-pdf-grande">
                        El PDF admite hasta {{ GenerarReporte::MAX_FILAS_PDF }} registros. Acota el periodo o descarga el Excel (CSV).
                    </p>
                @else
                    <flux:button size="sm" variant="primary" icon="document-arrow-down" :href="route('admin.reportes.descargar', [...$this->parametrosDescarga(), 'formato' => 'pdf'])">Descargar PDF</flux:button>
                @endif
                <flux:button size="sm" icon="table-cells" :href="route('admin.reportes.descargar', [...$this->parametrosDescarga(), 'formato' => 'csv'])">Descargar Excel (CSV)</flux:button>
            </div>
        </div>
    </div>

    {{-- RESULTADO --}}
    @php
        $reporte = $this->reporte;
        $totalFilas = count($reporte['filas']);
        $limiteFilas = 200;
        $filas = array_slice($reporte['filas'], 0, $limiteFilas);
        $alineadoDerecha = fn (string $tipo) => in_array($tipo, ['dinero', 'numero', 'porcentaje'], true);
        $clasesEtiqueta = [
            'positive' => 'bg-positive-soft text-positive',
            'negative' => 'bg-negative/10 text-negative',
            'warning' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
            'neutral' => 'bg-accent-soft text-accent-content',
        ];
    @endphp

    <div wire:loading.class="opacity-60" class="space-y-6 transition-opacity">
        <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            @foreach ($reporte['resumen'] as $indicador)
                <x-stat-card
                    wire:key="kpi-{{ $tipo }}-{{ $loop->index }}"
                    :label="$indicador['etiqueta']"
                    :icon="$indicador['icono']"
                    :tone="in_array($indicador['tono'], ['positive', 'negative'], true) ? $indicador['tono'] : 'neutral'"
                >
                    @if ($indicador['tipo'] === 'texto')
                        <span class="block truncate text-base font-semibold" title="{{ $indicador['valor'] }}">{{ $indicador['valor'] }}</span>
                    @else
                        {{ GenerarReporte::formatear($indicador['valor'], $indicador['tipo']) }}
                    @endif
                </x-stat-card>
            @endforeach
        </div>

        @if ($totalFilas === 0)
            <div class="rounded-2xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
                <div class="mx-auto flex size-12 items-center justify-center rounded-xl bg-accent-soft text-accent-content">
                    <flux:icon name="document-chart-bar" class="size-6" />
                </div>
                <p class="mt-3 font-medium text-zinc-900 dark:text-white">No hay información para este reporte</p>
                <p class="mt-1 text-sm text-zinc-500">Prueba con otro periodo o con otra sucursal.</p>
            </div>
        @else
            <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-sm shadow-zinc-900/5 dark:border-zinc-700/70 dark:bg-zinc-900 dark:shadow-none">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200/70 px-5 py-3 dark:border-zinc-700/70">
                    <h2 class="text-base font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $reporte['titulo'] }}</h2>
                    <span class="text-xs text-zinc-500">{{ number_format($totalFilas) }} {{ $totalFilas === 1 ? 'registro' : 'registros' }}</span>
                </div>

                <div class="max-h-[32rem] overflow-auto">
                    <table class="w-full min-w-[640px] text-left text-sm">
                        <thead>
                            <tr class="sticky top-0 z-10 border-b border-zinc-200/70 bg-zinc-50 text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-700/70 dark:bg-zinc-800">
                                @foreach ($reporte['columnas'] as $columna)
                                    <th class="whitespace-nowrap px-4 py-3 {{ $alineadoDerecha($columna['tipo']) ? 'text-right' : '' }}">{{ $columna['etiqueta'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($filas as $fila)
                                <tr class="border-b border-zinc-100 last:border-b-0 hover:bg-zinc-50/80 dark:border-zinc-800 dark:hover:bg-white/5">
                                    @foreach ($reporte['columnas'] as $columna)
                                        @php $valor = $fila[$columna['clave']] ?? null; @endphp
                                        <td @class([
                                            'px-4 py-2.5',
                                            'whitespace-nowrap text-right tabular-nums' => $alineadoDerecha($columna['tipo']),
                                            'whitespace-nowrap tabular-nums text-zinc-600 dark:text-zinc-400' => $columna['tipo'] === 'fecha',
                                            'whitespace-nowrap font-medium text-zinc-900 dark:text-white' => $loop->first && $columna['tipo'] === 'texto',
                                            'text-zinc-700 dark:text-zinc-300' => $columna['tipo'] === 'texto' && ! $loop->first,
                                        ])>
                                            @if ($columna['tipo'] === 'etiqueta')
                                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $clasesEtiqueta[GenerarReporte::tonoDeEtiqueta((string) $valor)] }}">{{ $valor }}</span>
                                            @else
                                                {{ GenerarReporte::formatear($valor, $columna['tipo']) }}
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                        @if ($reporte['totales'] !== [])
                            <tfoot>
                                <tr class="sticky bottom-0 border-t border-zinc-200/70 bg-accent-soft text-sm font-semibold text-zinc-900 dark:border-zinc-700/70 dark:bg-zinc-800 dark:text-white">
                                    @foreach ($reporte['columnas'] as $columna)
                                        <td class="whitespace-nowrap px-4 py-3 {{ $alineadoDerecha($columna['tipo']) ? 'text-right tabular-nums' : '' }}">
                                            @if ($loop->first)
                                                Total
                                            @elseif (array_key_exists($columna['clave'], $reporte['totales']))
                                                {{ GenerarReporte::formatear($reporte['totales'][$columna['clave']], $columna['tipo']) }}
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>

                @if ($totalFilas > $limiteFilas)
                    <p class="border-t border-zinc-200/70 px-5 py-3 text-xs text-zinc-500 dark:border-zinc-700/70">
                        Mostrando los primeros {{ $limiteFilas }} de {{ number_format($totalFilas) }} registros. El Excel (CSV) incluye todos.
                    </p>
                @endif
            </div>
        @endif
    </div>
</div>
