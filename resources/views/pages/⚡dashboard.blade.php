<?php

use App\Models\ActivityLog;
use App\Models\Cuenta;
use App\Models\Gasto;
use App\Models\Merma;
use App\Models\Sucursal;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    public bool $isAdmin = false;

    public ?int $sucursalId = null;

    public $ventasHoy = 0;

    public $ventasSemana = 0;

    public $ventasMes = 0;

    public $cuentasMes = 0;

    public $cuentasPendientes = 0;

    public $cuentasParcial = 0;

    public $cuentasPagadas = 0;

    public $faltantesMes = 0;

    public $sobrantesMes = 0;

    public $gastosMes = 0;

    public $mermasMes = 0;

    public array $topSucursales = [];

    public array $recentCuentas = [];

    // Gráfica anual
    public $chartSucursalId = '';

    public array $chartYears = [];

    public $chartYear;

    /** @var array{series: list<array<string, mixed>>, anio: int, anioAnterior: int} */
    public array $chartData = [];

    /** @var array{total: float, promedio: float, mejorMes: ?string, mejorMonto: float, anterior: float, variacion: ?float, periodo: string} */
    public array $chartResumen = [];

    public function mount(): void
    {
        $user = Auth::user();
        $this->isAdmin = $user->hasAnyRole(['Admin', 'Super Admin']);
        $this->sucursalId = $user->sucursal_id;

        $this->loadStats();

        if ($this->isAdmin) {
            $this->chartYears = $this->cargarAniosDisponibles();
            $this->chartYear = $this->chartYears[0];
            $this->actualizarGrafica();
        }
    }

    private function scopeSucursal($query)
    {
        if (! $this->isAdmin) {
            $query->where('sucursal_id', $this->sucursalId);
        }

        return $query;
    }

    private function loadStats(): void
    {
        $hoy = now()->toDateString();
        $inicioSemana = now()->startOfWeek()->toDateString();
        $inicioMes = now()->startOfMonth()->toDateString();
        $finMes = now()->endOfMonth()->toDateString();

        $this->ventasHoy = $this->scopeSucursal(Cuenta::query())
            ->whereDate('fecha_venta', $hoy)
            ->sum('total_venta');

        $this->ventasSemana = $this->scopeSucursal(Cuenta::query())
            ->whereBetween('fecha_venta', [$inicioSemana, $hoy])
            ->sum('total_venta');

        $cuentasMesQuery = $this->scopeSucursal(Cuenta::query())
            ->whereBetween('fecha_venta', [$inicioMes, $finMes])
            ->where('total_venta', '!=', 0);

        $this->ventasMes = (clone $cuentasMesQuery)->sum('total_venta');
        $this->cuentasMes = (clone $cuentasMesQuery)->count();
        $this->faltantesMes = (clone $cuentasMesQuery)->where('diferencia', '>', 0)->sum('diferencia');
        $this->sobrantesMes = abs((clone $cuentasMesQuery)->where('diferencia', '<', 0)->sum('diferencia'));
        $this->cuentasPendientes = (clone $cuentasMesQuery)->where('status_cuenta_id', 1)->count();
        $this->cuentasParcial = (clone $cuentasMesQuery)->where('status_cuenta_id', 2)->count();
        $this->cuentasPagadas = (clone $cuentasMesQuery)->where('status_cuenta_id', 3)->count();

        $this->gastosMes = $this->scopeSucursal(Gasto::query())
            ->whereHas('cuenta', fn ($q) => $q->whereBetween('fecha_venta', [$inicioMes, $finMes]))
            ->sum('precio');

        $this->mermasMes = $this->scopeSucursal(Merma::query())
            ->whereHas('cuenta', fn ($q) => $q->whereBetween('fecha_venta', [$inicioMes, $finMes]))
            ->sum('precio');

        if ($this->isAdmin) {
            $this->topSucursales = Cuenta::select('sucursal_id', DB::raw('SUM(total_venta) as total'))
                ->whereBetween('fecha_venta', [$inicioMes, $finMes])
                ->where('total_venta', '!=', 0)
                ->groupBy('sucursal_id')
                ->orderByDesc('total')
                ->with('sucursal:id,name')
                ->take(5)
                ->get()
                ->map(fn ($row) => [
                    'name' => $row->sucursal->name ?? '—',
                    'total' => (float) $row->total,
                ])
                ->toArray();
        }

        $this->recentCuentas = $this->scopeSucursal(Cuenta::query())
            ->with(['sucursal:id,name', 'status_cuenta'])
            ->where('total_venta', '!=', 0)
            ->orderByDesc('fecha_venta')
            ->orderByDesc('id')
            ->take(6)
            ->get()
            ->map(fn ($cuenta) => [
                'id' => $cuenta->id,
                'fecha' => Carbon::parse($cuenta->fecha_venta)->format('d/m/Y'),
                'sucursal' => $cuenta->sucursal->name ?? '—',
                'total' => (float) $cuenta->total_venta,
                'diferencia' => (float) $cuenta->diferencia,
                'status' => $cuenta->status_cuenta->name ?? '',
            ])
            ->toArray();
    }

    private function cargarAniosDisponibles(): array
    {
        $years = Cuenta::query()
            ->pluck('fecha_venta')
            ->filter()
            ->map(fn ($f) => (int) Carbon::parse($f)->format('Y'))
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        if (! in_array((int) date('Y'), $years, true)) {
            array_unshift($years, (int) date('Y'));
        }

        return $years;
    }

    public function updatedChartSucursalId(): void
    {
        $this->actualizarGrafica();
    }

    public function updatedChartYear(): void
    {
        $this->actualizarGrafica();
    }

    /**
     * Ventas por sucursal y mes de un año: [sucursal_id => [mes => total]].
     *
     * @param  iterable<int>  $sucursalIds
     * @return array<int, array<int, float>>
     */
    private function ventasPorSucursalYMes(iterable $sucursalIds, int $anio): array
    {
        return Cuenta::query()
            ->whereIn('sucursal_id', $sucursalIds)
            ->whereBetween('fecha_venta', ["{$anio}-01-01", "{$anio}-12-31"])
            ->get(['sucursal_id', 'fecha_venta', 'total_venta'])
            ->groupBy('sucursal_id')
            ->map(fn ($cuentas) => $cuentas
                ->groupBy(fn ($cuenta) => (int) Carbon::parse($cuenta->fecha_venta)->format('n'))
                ->map(fn ($delMes) => (float) $delMes->sum('total_venta'))
                ->all())
            ->all();
    }

    public function actualizarGrafica(): void
    {
        abort_unless(Auth::user()->hasAnyRole(['Admin', 'Super Admin']), 403);

        $colores = ['#6c63e8', '#22c1a8', '#f5a524', '#f0586f', '#38bdf8', '#a78bfa', '#34c759', '#64748b', '#c084fc'];
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        $anio = in_array((int) $this->chartYear, $this->chartYears, true) ? (int) $this->chartYear : (int) date('Y');
        $anioAnterior = $anio - 1;

        $todas = Sucursal::orderBy('id')->get();
        $indiceColor = $todas->pluck('id')->flip();
        $sucursales = $this->chartSucursalId
            ? $todas->where('id', (int) $this->chartSucursalId)
            : $todas;
        $ids = $sucursales->pluck('id');

        $actual = $this->ventasPorSucursalYMes($ids, $anio);
        $previo = $this->ventasPorSucursalYMes($ids, $anioAnterior);

        // Meses con datos: el año en curso llega hasta el mes actual.
        $limite = $anio === now()->year ? now()->month : ($anio < now()->year ? 12 : 0);

        $totalesMes = array_fill(1, 12, 0.0);
        $series = [];

        foreach ($sucursales as $sucursal) {
            $data = [];
            $totalAnual = 0.0;

            for ($m = 1; $m <= 12; $m++) {
                $monto = round($actual[$sucursal->id][$m] ?? 0, 2);
                $totalesMes[$m] += $monto;
                $totalAnual += $monto;
                $data[] = $m > $limite ? null : $monto;
            }

            $series[] = [
                'type' => 'column',
                'name' => $sucursal->name,
                'data' => $data,
                'color' => $colores[$indiceColor[$sucursal->id] % count($colores)],
                'total' => $totalAnual,
            ];
        }

        // La sucursal con más ventas va abajo de la pila.
        usort($series, fn ($a, $b) => $b['total'] <=> $a['total']);
        $series = array_map(function ($serie) {
            unset($serie['total']);

            return $serie;
        }, $series);

        $totalesPrevios = array_fill(1, 12, 0.0);
        foreach ($previo as $meses_sucursal) {
            foreach ($meses_sucursal as $m => $monto) {
                $totalesPrevios[$m] += $monto;
            }
        }

        if (array_sum($totalesPrevios) > 0) {
            $series[] = [
                'type' => 'line',
                'name' => "Total {$anioAnterior}",
                'data' => array_map(fn ($monto) => round($monto, 2), array_values($totalesPrevios)),
                'color' => '#94a3b8',
                'dashStyle' => 'ShortDash',
                'zIndex' => 5,
                'isComparativo' => true,
            ];
        }

        $total = 0.0;
        $anteriorMismoPeriodo = 0.0;
        $mejorMes = null;
        $mejorMonto = 0.0;

        for ($m = 1; $m <= $limite; $m++) {
            $total += $totalesMes[$m];
            $anteriorMismoPeriodo += $totalesPrevios[$m];

            if ($totalesMes[$m] > $mejorMonto) {
                $mejorMonto = $totalesMes[$m];
                $mejorMes = $meses[$m - 1];
            }
        }

        $this->chartData = ['series' => $series, 'anio' => $anio, 'anioAnterior' => $anioAnterior];

        $this->chartResumen = [
            'total' => $total,
            'promedio' => $limite > 0 ? $total / $limite : 0.0,
            'mejorMes' => $mejorMes,
            'mejorMonto' => $mejorMonto,
            'anterior' => $anteriorMismoPeriodo,
            'variacion' => $anteriorMismoPeriodo > 0 ? (($total - $anteriorMismoPeriodo) / $anteriorMismoPeriodo) * 100 : null,
            'periodo' => $limite === 0
                ? 'Sin meses transcurridos'
                : ($limite === 12 ? 'Año completo' : 'Enero a '.$meses[$limite - 1]),
        ];
    }

    #[Computed]
    public function sucursalesChart()
    {
        return Sucursal::orderBy('name')->get();
    }

    #[Computed]
    public function actividades()
    {
        if (! $this->isAdmin) {
            return null;
        }

        return ActivityLog::query()
            ->with('user.roles')
            ->latest('created_at')
            ->limit(8)
            ->get();
    }
}; ?>

<div class="space-y-6">
    {{-- ENCABEZADO --}}
    @php
        $fechaHoy = \Illuminate\Support\Str::ucfirst(now()->locale('es')->translatedFormat('l, j \d\e F \d\e Y'));
        $saludo = 'Hola, '.\Illuminate\Support\Str::of(Auth::user()->name)->before(' ')->lower()->title();
        $resumen = $fechaHoy.' · Resumen de la operación '.($isAdmin ? 'de todas las sucursales' : 'de '.(Auth::user()->sucursal->name ?? 'tu sucursal')).'.';
    @endphp
    <x-page-header icon="home" :title="$saludo" :subtitle="$resumen">
        <flux:button
            variant="primary"
            icon="plus"
            :href="$isAdmin ? route('admin.registrar.index') : route('capturista.registrar.index')"
            wire:navigate
        >
            Registrar cuenta
        </flux:button>
    </x-page-header>

    {{-- VENTAS --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4">
        <x-stat-card label="Ventas de hoy" icon="banknotes" size="lg">
            ${{ number_format($ventasHoy, 2) }}
        </x-stat-card>
        <x-stat-card label="Ventas de la semana" icon="arrow-trending-up" size="lg">
            ${{ number_format($ventasSemana, 2) }}
        </x-stat-card>
        <x-stat-card label="Ventas del mes" icon="chart-bar" size="lg" tone="brand" class="col-span-2 sm:col-span-1">
            ${{ number_format($ventasMes, 2) }}
            <x-slot:footer>{{ $cuentasMes }} {{ $cuentasMes === 1 ? 'cuenta' : 'cuentas' }} este mes</x-slot:footer>
        </x-stat-card>
    </div>

    {{-- INDICADORES DEL MES --}}
    @php
        $totalEstatus = $cuentasPendientes + $cuentasParcial + $cuentasPagadas;
        $estatus = [
            ['label' => 'Pendientes', 'count' => $cuentasPendientes, 'bar' => 'bg-negative'],
            ['label' => 'Pago parcial', 'count' => $cuentasParcial, 'bar' => 'bg-accent'],
            ['label' => 'Pagadas', 'count' => $cuentasPagadas, 'bar' => 'bg-positive'],
        ];
    @endphp
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:col-span-2">
            <x-stat-card label="Faltantes del mes" icon="exclamation-triangle" :tone="$faltantesMes > 0 ? 'negative' : 'neutral'">
                ${{ number_format($faltantesMes, 2) }}
            </x-stat-card>
            <x-stat-card label="Sobrantes del mes" icon="square-3-stack-3d">
                ${{ number_format($sobrantesMes, 2) }}
            </x-stat-card>
            <x-stat-card label="Gastos del mes" icon="credit-card">
                ${{ number_format($gastosMes, 2) }}
            </x-stat-card>
            <x-stat-card label="Merma del mes" icon="trash">
                ${{ number_format($mermasMes, 2) }}
            </x-stat-card>
        </div>

        <x-section-card icon="chart-pie" title="Estatus de cuentas" subtitle="Cuentas registradas este mes.">
            @if ($totalEstatus === 0)
                <p class="py-6 text-center text-sm text-zinc-500">Sin cuentas este mes.</p>
            @else
                <div class="flex h-2.5 w-full gap-0.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                    @foreach ($estatus as $e)
                        @if ($e['count'] > 0)
                            <div class="{{ $e['bar'] }} h-full" style="width: {{ $e['count'] / $totalEstatus * 100 }}%"></div>
                        @endif
                    @endforeach
                </div>
                <ul class="mt-4 space-y-2.5">
                    @foreach ($estatus as $e)
                        <li class="flex items-center justify-between gap-3 text-sm">
                            <span class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
                                <span class="size-2 rounded-full {{ $e['bar'] }}"></span>
                                {{ $e['label'] }}
                            </span>
                            <span class="tabular-nums">
                                <span class="font-semibold text-zinc-900 dark:text-white">{{ $e['count'] }}</span>
                                <span class="ml-1 text-xs text-zinc-500">{{ round($e['count'] / $totalEstatus * 100) }}%</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-section-card>
    </div>

    <div class="grid grid-cols-1 items-start gap-6 {{ $isAdmin ? 'lg:grid-cols-[1fr_340px]' : '' }}">
        {{-- ÚLTIMAS CUENTAS --}}
        <x-section-card icon="ticket" title="Últimas cuentas" subtitle="Los registros más recientes del sistema.">
            @if (empty($recentCuentas))
                <p class="py-8 text-center text-sm text-zinc-500">Aún no hay cuentas registradas.</p>
            @else
                <div class="-mx-2 divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($recentCuentas as $cuenta)
                        @php
                            $color = match (strtoupper($cuenta['status'])) {
                                'PENDIENTE' => 'red',
                                'PAGO PARCIAL' => 'purple',
                                'PAGADO' => 'green',
                                default => 'zinc',
                            };
                        @endphp
                        <a
                            href="{{ $isAdmin ? route('admin.cuentas.show', $cuenta['id']) : '#' }}"
                            @if ($isAdmin) wire:navigate @endif
                            class="flex items-center gap-3 rounded-xl px-2 py-3 transition {{ $isAdmin ? 'hover:bg-zinc-50 dark:hover:bg-zinc-800/60' : 'pointer-events-none' }}"
                        >
                            <span class="flex size-10 flex-none items-center justify-center rounded-full bg-accent-soft text-sm font-semibold text-accent">
                                {{ mb_strtoupper(mb_substr($cuenta['sucursal'], 0, 1)) }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $cuenta['sucursal'] }}</div>
                                <div class="text-xs text-zinc-500 tabular-nums">
                                    {{ $cuenta['fecha'] }}
                                    @if ($cuenta['diferencia'] > 0)
                                        <span class="font-medium text-negative">· Faltante ${{ number_format($cuenta['diferencia'], 2) }}</span>
                                    @elseif ($cuenta['diferencia'] < 0)
                                        <span class="font-medium text-positive">· Sobrante ${{ number_format(abs($cuenta['diferencia']), 2) }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="flex flex-none flex-col items-end gap-1">
                                <span class="text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">${{ number_format($cuenta['total'], 2) }}</span>
                                <flux:badge :color="$color" size="sm">{{ ucwords(strtolower($cuenta['status'])) }}</flux:badge>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-section-card>

        @if ($isAdmin)
            {{-- TOP SUCURSALES --}}
            <x-section-card icon="building-storefront" title="Top sucursales" subtitle="Las que más vendieron este mes.">
                @if (empty($topSucursales))
                    <p class="py-8 text-center text-sm text-zinc-500">Sin ventas este mes.</p>
                @else
                    @php $max = max(array_column($topSucursales, 'total')) ?: 1; @endphp
                    <ol class="flex flex-col gap-4">
                        @foreach ($topSucursales as $i => $s)
                            <li class="flex items-center gap-3">
                                <span class="flex size-6 flex-none items-center justify-center rounded-full text-xs font-semibold {{ $i === 0 ? 'bg-accent text-accent-foreground' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800' }}">{{ $i + 1 }}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-baseline justify-between gap-2 text-sm">
                                        <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $s['name'] }}</span>
                                        <span class="flex-none tabular-nums text-zinc-500 dark:text-zinc-400">${{ number_format($s['total'], 2) }}</span>
                                    </div>
                                    <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                        <div class="h-full rounded-full bg-accent" style="width: {{ max(4, round($s['total'] / $max * 100)) }}%"></div>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-section-card>
        @endif
    </div>

    @if ($isAdmin)
        {{-- GRÁFICA ANUAL --}}
        <x-section-card icon="chart-bar" title="Resumen anual" subtitle="Ventas de cada mes, por sucursal, comparadas con el año anterior.">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <flux:select wire:model.live="chartSucursalId" size="sm" class="sm:w-60" aria-label="Sucursal">
                    <flux:select.option value="">Todas las sucursales</flux:select.option>
                    @foreach ($this->sucursalesChart as $sucursal)
                        <flux:select.option value="{{ $sucursal->id }}">{{ $sucursal->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="chartYear" size="sm" class="sm:w-32" aria-label="Año">
                    @foreach ($chartYears as $year)
                        <flux:select.option value="{{ $year }}">{{ $year }}</flux:select.option>
                    @endforeach
                </flux:select>
                <span wire:loading wire:target="chartSucursalId,chartYear" class="text-xs text-zinc-500">Actualizando...</span>
            </div>

            @php
                $resumenAnual = $chartResumen;
                $variacion = $resumenAnual['variacion'] ?? null;
            @endphp
            <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <x-stat-card label="Ventas del año" icon="banknotes">
                    ${{ number_format($resumenAnual['total'] ?? 0, 2) }}
                    <x-slot:footer>{{ $resumenAnual['periodo'] ?? '' }}</x-slot:footer>
                </x-stat-card>
                <x-stat-card label="Promedio mensual" icon="calculator">
                    ${{ number_format($resumenAnual['promedio'] ?? 0, 2) }}
                    <x-slot:footer>por mes transcurrido</x-slot:footer>
                </x-stat-card>
                <x-stat-card label="Mejor mes" icon="trophy">
                    {{ ! empty($resumenAnual['mejorMes']) ? \Illuminate\Support\Str::ucfirst($resumenAnual['mejorMes']) : '—' }}
                    <x-slot:footer>{{ ! empty($resumenAnual['mejorMes']) ? '$'.number_format($resumenAnual['mejorMonto'], 2) : 'Sin ventas aún' }}</x-slot:footer>
                </x-stat-card>
                <x-stat-card
                    label="Contra {{ $chartData['anioAnterior'] ?? '' }}"
                    :icon="$variacion !== null && $variacion < 0 ? 'arrow-trending-down' : 'arrow-trending-up'"
                    :tone="$variacion === null ? 'neutral' : ($variacion < 0 ? 'negative' : 'positive')"
                >
                    {{ $variacion === null ? '—' : ($variacion > 0 ? '+' : '').number_format($variacion, 1).'%' }}
                    <x-slot:footer>{{ $variacion === null ? 'Sin ventas el año anterior' : 'mismo periodo, antes $'.number_format($resumenAnual['anterior'], 2) }}</x-slot:footer>
                </x-stat-card>
            </div>

            @assets
                <script src="https://code.highcharts.com/highcharts.js"></script>
                <script src="https://code.highcharts.com/modules/accessibility.js"></script>
            @endassets

            <div
                wire:ignore
                x-data="{
                    chart: null,
                    observer: null,
                    data: @entangle('chartData'),
                    money(v) {
                        return '$' + Highcharts.numberFormat(v, 2, '.', ',');
                    },
                    compact(v) {
                        if (v >= 1000000) return '$' + (v / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
                        if (v >= 1000) return '$' + Math.round(v / 1000) + 'k';
                        return '$' + Math.round(v);
                    },
                    esc(t) {
                        return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    },
                    render() {
                        if (this.chart) this.chart.destroy();
                        if (!this.data || !this.data.series || this.data.series.length === 0) return;
                        const self = this;
                        const dark = document.documentElement.classList.contains('dark');
                        const text = dark ? '#a8a8b0' : '#7a829f';
                        const strong = dark ? '#fafafa' : '#1a2038';
                        const grid = dark ? '#2a2a30' : '#e5e8f3';
                        const series = this.data.series.map((s) => s.isComparativo
                            ? { ...s, type: 'line', lineWidth: 2.5, marker: { enabled: true, radius: 3, symbol: 'circle' }, states: { hover: { lineWidthPlus: 0 } } }
                            : { ...s, type: 'column' });
                        this.chart = Highcharts.chart(this.$refs.container, {
                            chart: { backgroundColor: 'transparent', style: { fontFamily: 'inherit' }, spacing: [18, 0, 0, 0] },
                            title: { text: null },
                            xAxis: {
                                categories: ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'],
                                lineColor: grid, tickLength: 0, labels: { style: { color: text } },
                            },
                            yAxis: {
                                title: { text: null }, gridLineColor: grid, min: 0,
                                labels: { style: { color: text }, formatter() { return self.compact(this.value); } },
                                stackLabels: {
                                    enabled: true,
                                    style: { color: text, fontWeight: '600', fontSize: '10px', textOutline: 'none' },
                                    formatter() { return this.total > 0 ? self.compact(this.total) : ''; },
                                },
                            },
                            legend: { itemStyle: { color: text, fontWeight: '500' }, itemHoverStyle: { color: strong } },
                            tooltip: {
                                shared: true, useHTML: true,
                                backgroundColor: dark ? '#1f1f23' : '#ffffff', borderColor: grid, borderRadius: 12,
                                style: { color: strong },
                                formatter() {
                                    let total = 0, previo = null, filas = '';
                                    this.points.slice().reverse().forEach((p) => {
                                        if (p.series.type === 'line') { previo = p; return; }
                                        total += p.y || 0;
                                        if (p.y > 0) filas += `<div style='display:flex;justify-content:space-between;gap:16px'><span><span style='color:${p.color}'>●</span> ${self.esc(p.series.name)}</span><b>${self.money(p.y)}</b></div>`;
                                    });
                                    let html = `<div style='font-weight:600;margin-bottom:4px'>${self.esc(this.x)} ${self.data.anio}</div>`;
                                    html += filas || `<div style='color:${text}'>Sin ventas</div>`;
                                    html += `<div style='display:flex;justify-content:space-between;gap:16px;border-top:1px solid ${grid};margin-top:4px;padding-top:4px'><span>Total</span><b>${self.money(total)}</b></div>`;
                                    if (previo) {
                                        const diff = previo.y > 0 ? ((total - previo.y) / previo.y) * 100 : null;
                                        const dif = diff === null ? '' : ` <span style='color:${diff >= 0 ? '#23885a' : '#cf3f55'}'>(${diff >= 0 ? '+' : ''}${diff.toFixed(1)}%)</span>`;
                                        html += `<div style='display:flex;justify-content:space-between;gap:16px;color:${text}'><span>${self.esc(previo.series.name)}</span><span>${self.money(previo.y)}${dif}</span></div>`;
                                    }
                                    return html;
                                },
                            },
                            plotOptions: {
                                column: { stacking: 'normal', borderWidth: 0, borderRadius: 3, maxPointWidth: 44, groupPadding: 0.12 },
                            },
                            responsive: {
                                rules: [{ condition: { maxWidth: 600 }, chartOptions: { yAxis: { stackLabels: { enabled: false } } } }],
                            },
                            series: series,
                            credits: { enabled: false },
                        });
                    },
                }"
                x-init="
                    render();
                    $watch('data', () => render());
                    observer = new MutationObserver(() => render());
                    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
                "
                x-on:livewire:navigating.window="observer && observer.disconnect()"
                class="mt-5 transition-opacity"
                wire:loading.class="opacity-50"
                wire:target="chartSucursalId,chartYear"
            >
                <div x-ref="container" style="width: 100%; height: 380px;"></div>
            </div>
        </x-section-card>

        {{-- ACTIVIDAD --}}
        <x-section-card icon="clock" title="Actividad reciente" subtitle="Lo último que hicieron los usuarios en el sistema.">
            <x-slot:actions>
                <flux:button size="sm" icon-trailing="arrow-right" :href="route('admin.auditoria.index')" wire:navigate>Ver historial completo</flux:button>
            </x-slot:actions>
            <div class="-mx-2 divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->actividades as $log)
                    @php
                        $nombre = $log->user->name ?? 'Usuario eliminado';
                        $esAdminLog = $log->user?->hasAnyRole(['Admin', 'Super Admin']);
                    @endphp
                    <div class="flex items-start gap-3 px-2 py-3" wire:key="log-{{ $log->id }}">
                        <flux:avatar size="sm" :initials="mb_strtoupper(mb_substr($nombre, 0, 1))" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm leading-snug text-zinc-600 dark:text-zinc-300">
                                <span class="font-semibold text-zinc-900 dark:text-white">{{ $nombre }}</span>
                                @if ($log->user)
                                    <flux:badge size="sm" :color="$esAdminLog ? 'blue' : 'green'">{{ $esAdminLog ? 'Administrador' : 'Capturista' }}</flux:badge>
                                @endif
                                {{ $log->description }}
                            </p>
                            <p class="mt-0.5 text-xs text-zinc-500">
                                {{ $log->created_at->diffForHumans() }}
                                @if ($log->ip_address)
                                    <span>· {{ $log->ip_address }}</span>
                                @endif
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="py-10 text-center text-sm text-zinc-500">Todavía no hay actividad registrada.</p>
                @endforelse
            </div>
        </x-section-card>
    @endif
</div>
