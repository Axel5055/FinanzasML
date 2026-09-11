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
use Livewire\WithPagination;

new #[Title('Dashboard')] class extends Component {
    use WithPagination;

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

    public array $chartData = [];

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

    public function actualizarGrafica(): void
    {
        $colores = ['#2A9D8F', '#264653', '#F4A261', '#E76F51', '#E9C46A', '#A5A58D', '#457B9D', '#1D3557', '#B8A397'];

        $sucursales = $this->chartSucursalId
            ? Sucursal::where('id', $this->chartSucursalId)->get()
            : Sucursal::all();

        $registros = Cuenta::query()
            ->whereIn('sucursal_id', $sucursales->pluck('id'))
            ->whereYear('fecha_venta', $this->chartYear)
            ->get(['sucursal_id', 'fecha_venta', 'total_venta'])
            ->groupBy('sucursal_id');

        $this->chartData = $sucursales->values()->map(function ($sucursal, $index) use ($registros, $colores) {
            $porMes = ($registros->get($sucursal->id) ?? collect())
                ->groupBy(fn ($c) => (int) Carbon::parse($c->fecha_venta)->format('n'));

            $data = [];
            for ($m = 1; $m <= 12; $m++) {
                $data[] = (int) ($porMes->get($m)?->sum('total_venta') ?? 0);
            }

            return [
                'name' => $sucursal->name,
                'data' => $data,
                'color' => $colores[$index % count($colores)],
            ];
        })->values()->toArray();
    }

    #[Computed]
    public function sucursalesChart()
    {
        return Sucursal::orderBy('name')->get();
    }

    #[Computed]
    public function sucursalesExport()
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
            ->paginate(10);
    }
}; ?>

<div class="space-y-6">
    <div class="flex items-center gap-3">
        <x-app-logo />
        <div>
            <flux:heading size="lg">Bienvenido, {{ Auth::user()->name }}</flux:heading>
            <flux:subheading>
                Este es el resumen de la operación {{ $isAdmin ? 'de todas las sucursales' : 'de '.(Auth::user()->sucursal->name ?? 'tu sucursal') }}.
            </flux:subheading>
        </div>
    </div>

    {{-- KPIs --}}
    <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
        <flux:card>
            <div class="flex items-center gap-2 mb-2">
                <span class="w-7 h-7 rounded flex items-center justify-center flex-none bg-accent-soft text-accent">
                    <flux:icon name="banknotes" class="w-4 h-4" />
                </span>
                <span class="text-[10.5px] font-bold uppercase tracking-wide text-zinc-500">Ventas de hoy</span>
            </div>
            <flux:heading size="lg" class="tabular-nums">${{ number_format($ventasHoy, 2) }}</flux:heading>
        </flux:card>
        <flux:card>
            <div class="flex items-center gap-2 mb-2">
                <span class="w-7 h-7 rounded flex items-center justify-center flex-none bg-accent-soft text-accent">
                    <flux:icon name="arrow-trending-up" class="w-4 h-4" />
                </span>
                <span class="text-[10.5px] font-bold uppercase tracking-wide text-zinc-500">Ventas de la semana</span>
            </div>
            <flux:heading size="lg" class="tabular-nums">${{ number_format($ventasSemana, 2) }}</flux:heading>
        </flux:card>
        <flux:card>
            <div class="flex items-center gap-2 mb-2">
                <span class="w-7 h-7 rounded flex items-center justify-center flex-none bg-accent-soft text-accent">
                    <flux:icon name="chart-bar" class="w-4 h-4" />
                </span>
                <span class="text-[10.5px] font-bold uppercase tracking-wide text-zinc-500">Ventas del mes</span>
            </div>
            <flux:heading size="lg" class="tabular-nums">${{ number_format($ventasMes, 2) }}</flux:heading>
        </flux:card>
        <flux:card>
            <div class="flex items-center gap-2 mb-2">
                <span class="w-7 h-7 rounded flex items-center justify-center flex-none bg-accent-soft text-accent">
                    <flux:icon name="ticket" class="w-4 h-4" />
                </span>
                <span class="text-[10.5px] font-bold uppercase tracking-wide text-zinc-500">Cuentas del mes</span>
            </div>
            <flux:heading size="lg" class="tabular-nums">{{ $cuentasMes }}</flux:heading>
        </flux:card>
        <flux:card class="{{ $faltantesMes > 0 ? 'bg-negative-soft border-negative-soft' : '' }}">
            <div class="flex items-center gap-2 mb-2">
                <span class="w-7 h-7 rounded flex items-center justify-center flex-none {{ $faltantesMes > 0 ? 'bg-negative text-white' : 'bg-accent-soft text-accent' }}">
                    <flux:icon name="exclamation-triangle" class="w-4 h-4" />
                </span>
                <span class="text-[10.5px] font-bold uppercase tracking-wide {{ $faltantesMes > 0 ? 'text-negative' : 'text-zinc-500' }}">Faltantes del mes</span>
            </div>
            <flux:heading size="lg" class="tabular-nums {{ $faltantesMes > 0 ? 'text-negative' : '' }}">${{ number_format($faltantesMes, 2) }}</flux:heading>
        </flux:card>
        <flux:card>
            <div class="flex items-center gap-2 mb-2">
                <span class="w-7 h-7 rounded flex items-center justify-center flex-none bg-accent-soft text-accent">
                    <flux:icon name="square-3-stack-3d" class="w-4 h-4" />
                </span>
                <span class="text-[10.5px] font-bold uppercase tracking-wide text-zinc-500">Sobrantes del mes</span>
            </div>
            <flux:heading size="lg" class="tabular-nums">${{ number_format($sobrantesMes, 2) }}</flux:heading>
        </flux:card>
        <flux:card>
            <div class="flex items-center gap-2 mb-2">
                <span class="w-7 h-7 rounded flex items-center justify-center flex-none bg-accent-soft text-accent">
                    <flux:icon name="credit-card" class="w-4 h-4" />
                </span>
                <span class="text-[10.5px] font-bold uppercase tracking-wide text-zinc-500">Gastos del mes</span>
            </div>
            <flux:heading size="lg" class="tabular-nums">${{ number_format($gastosMes, 2) }}</flux:heading>
        </flux:card>
        <flux:card>
            <div class="flex items-center gap-2 mb-2">
                <span class="w-7 h-7 rounded flex items-center justify-center flex-none bg-accent-soft text-accent">
                    <flux:icon name="trash" class="w-4 h-4" />
                </span>
                <span class="text-[10.5px] font-bold uppercase tracking-wide text-zinc-500">Merma del mes</span>
            </div>
            <flux:heading size="lg" class="tabular-nums">${{ number_format($mermasMes, 2) }}</flux:heading>
        </flux:card>
        <flux:card>
            <div class="flex items-center gap-2 mb-2">
                <span class="w-7 h-7 rounded flex items-center justify-center flex-none bg-accent-soft text-accent">
                    <flux:icon name="clock" class="w-4 h-4" />
                </span>
                <span class="text-[10.5px] font-bold uppercase tracking-wide text-zinc-500">Estatus del mes</span>
            </div>
            <div class="space-y-1 text-sm">
                <div class="flex justify-between"><span class="text-negative font-semibold">Pendientes</span><span class="font-bold tabular-nums">{{ $cuentasPendientes }}</span></div>
                <div class="flex justify-between"><span class="text-accent font-semibold">Pago parcial</span><span class="font-bold tabular-nums">{{ $cuentasParcial }}</span></div>
                <div class="flex justify-between"><span class="text-positive font-semibold">Pagadas</span><span class="font-bold tabular-nums">{{ $cuentasPagadas }}</span></div>
            </div>
        </flux:card>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-6 items-start">
        {{-- ÚLTIMAS CUENTAS --}}
        <flux:card>
            <div class="flex items-center gap-2 -mx-6 px-6 pb-4 mb-4 border-b border-zinc-200 dark:border-zinc-700">
                <span class="w-[26px] h-[26px] rounded bg-accent-soft text-accent flex items-center justify-center flex-none">
                    <flux:icon name="ticket" class="w-3.5 h-3.5" />
                </span>
                <flux:heading size="lg">Últimas cuentas registradas</flux:heading>
            </div>
            @if (empty($recentCuentas))
                <p class="text-center text-sm text-zinc-500 py-8">Aún no hay cuentas registradas.</p>
            @else
                <div class="mt-2 divide-y divide-zinc-200 dark:divide-zinc-700">
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
                            class="flex items-center justify-between gap-3 py-3 {{ $isAdmin ? 'hover:bg-zinc-50 dark:hover:bg-zinc-800' : 'pointer-events-none' }}"
                        >
                            <div class="min-w-0">
                                <div class="text-sm font-bold truncate">{{ $cuenta['sucursal'] }}</div>
                                <div class="text-xs text-zinc-500 tabular-nums">{{ $cuenta['fecha'] }}</div>
                            </div>
                            <div class="flex items-center gap-3 flex-none">
                                <span class="text-sm font-extrabold tabular-nums">${{ number_format($cuenta['total'], 2) }}</span>
                                <flux:badge :color="$color" size="sm">{{ ucwords(strtolower($cuenta['status'])) }}</flux:badge>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </flux:card>

        @if ($isAdmin)
            <flux:card>
                <div class="flex items-center gap-2 -mx-6 px-6 pb-4 mb-4 border-b border-zinc-200 dark:border-zinc-700">
                    <span class="w-[26px] h-[26px] rounded bg-accent-soft text-accent flex items-center justify-center flex-none">
                        <flux:icon name="building-storefront" class="w-3.5 h-3.5" />
                    </span>
                    <flux:heading size="lg">Top sucursales del mes</flux:heading>
                </div>
                @if (empty($topSucursales))
                    <p class="text-center text-sm text-zinc-500 py-8">Sin ventas este mes.</p>
                @else
                    @php $max = max(array_column($topSucursales, 'total')) ?: 1; @endphp
                    <div class="mt-3 flex flex-col gap-3.5">
                        @foreach ($topSucursales as $s)
                            <div>
                                <div class="flex items-center justify-between text-sm mb-1">
                                    <span class="font-bold truncate">{{ $s['name'] }}</span>
                                    <span class="font-bold text-zinc-500 tabular-nums">${{ number_format($s['total'], 2) }}</span>
                                </div>
                                <div class="h-1.5 bg-zinc-100 dark:bg-zinc-800 rounded-full overflow-hidden">
                                    <div class="h-full bg-accent-content rounded-full" style="width: {{ max(4, round($s['total'] / $max * 100)) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </flux:card>
        @endif
    </div>

    @if ($isAdmin)
        {{-- ACTIVIDAD --}}
        <flux:card>
            <div class="flex items-center gap-2 -mx-6 px-6 pb-4 mb-4 border-b border-zinc-200 dark:border-zinc-700">
                <span class="w-[26px] h-[26px] rounded bg-accent-soft text-accent flex items-center justify-center flex-none">
                    <flux:icon name="clock" class="w-3.5 h-3.5" />
                </span>
                <flux:heading size="lg">Registros de actividad</flux:heading>
            </div>
            <div class="mt-2 divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->actividades as $log)
                    @php
                        $nombre = $log->user->name ?? 'Usuario eliminado';
                        $esAdminLog = $log->user?->hasAnyRole(['Admin', 'Super Admin']);
                    @endphp
                    <div class="flex items-start gap-3 py-3" wire:key="log-{{ $log->id }}">
                        <flux:avatar size="sm" :initials="mb_strtoupper(mb_substr($nombre, 0, 1))" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm leading-snug">
                                <span class="font-bold">{{ $nombre }}</span>
                                @if ($log->user)
                                    <flux:badge size="sm" :color="$esAdminLog ? 'blue' : 'green'">{{ $esAdminLog ? 'Administrador' : 'Capturista' }}</flux:badge>
                                @endif
                                {{ $log->description }}
                            </p>
                            <p class="text-xs text-zinc-500 mt-0.5">
                                {{ $log->created_at->diffForHumans() }}
                                @if ($log->ip_address)
                                    <span>({{ $log->ip_address }})</span>
                                @endif
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="text-center text-sm text-zinc-500 py-10">Todavía no hay actividad registrada.</p>
                @endforelse
            </div>
            @if ($this->actividades?->hasPages())
                <div class="mt-3">{{ $this->actividades->links() }}</div>
            @endif
        </flux:card>

        {{-- GRÁFICA ANUAL --}}
        <flux:card>
            <div class="flex items-center gap-2 -mx-6 px-6 pb-4 mb-4 border-b border-zinc-200 dark:border-zinc-700">
                <span class="w-[26px] h-[26px] rounded bg-accent-soft text-accent flex items-center justify-center flex-none">
                    <flux:icon name="chart-bar" class="w-3.5 h-3.5" />
                </span>
                <flux:heading size="lg">Resumen anual</flux:heading>
            </div>
            <div class="flex flex-col md:flex-row gap-4 md:items-end">
                <flux:select wire:model="chartSucursalId" label="Sucursal" field:class="md:w-1/3">
                    <flux:select.option value="">Todas las sucursales</flux:select.option>
                    @foreach ($this->sucursalesChart as $sucursal)
                        <flux:select.option value="{{ $sucursal->id }}">{{ $sucursal->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="chartYear" label="Año" field:class="md:w-1/3">
                    @foreach ($chartYears as $year)
                        <flux:select.option value="{{ $year }}">{{ $year }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button wire:click="actualizarGrafica">Filtrar</flux:button>
            </div>

            @assets
                <script src="https://code.highcharts.com/highcharts.js"></script>
                <script src="https://code.highcharts.com/modules/accessibility.js"></script>
            @endassets

            <div
                wire:ignore
                x-data="{
                    chart: null,
                    data: @entangle('chartData'),
                    render() {
                        if (this.chart) this.chart.destroy();
                        if (!this.data || this.data.length === 0) return;
                        this.chart = Highcharts.chart(this.$refs.container, {
                            chart: { type: 'line', backgroundColor: 'transparent' },
                            title: { text: null },
                            xAxis: { categories: ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'] },
                            yAxis: { title: { text: 'Efectivo' } },
                            legend: { layout: 'horizontal', align: 'center', verticalAlign: 'bottom' },
                            series: this.data,
                            credits: { enabled: false },
                        });
                    },
                }"
                x-init="render(); $watch('data', () => render())"
                class="mt-4"
            >
                <div x-ref="container" style="width: 100%; height: 400px;"></div>
            </div>
        </flux:card>

        {{-- EXPORTAR REPORTE --}}
        <flux:card>
            <div class="flex items-center gap-2 -mx-6 px-6 pb-4 mb-4 border-b border-zinc-200 dark:border-zinc-700">
                <span class="w-[26px] h-[26px] rounded bg-accent-soft text-accent flex items-center justify-center flex-none">
                    <flux:icon name="arrow-down-tray" class="w-3.5 h-3.5" />
                </span>
                <flux:heading size="lg">Exportar reporte</flux:heading>
            </div>
            <flux:subheading>Descarga un PDF con las cuentas de una sucursal en un rango de fechas.</flux:subheading>

            <form action="{{ route('admin.cuentas.pdf.rango') }}" method="GET" target="_blank" class="mt-3 flex flex-col md:flex-row md:items-end gap-4">
                <flux:select name="sucursal_id" label="Sucursal" field:class="md:flex-1">
                    <flux:select.option value="">Selecciona una sucursal</flux:select.option>
                    @foreach ($this->sucursalesExport as $sucursal)
                        <flux:select.option value="{{ $sucursal->id }}">{{ $sucursal->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input type="date" name="start_date" label="Fecha de inicio" field:class="md:flex-1" />
                <flux:input type="date" name="end_date" label="Fecha de fin" field:class="md:flex-1" />
                <flux:button type="submit" variant="primary">Exportar PDF</flux:button>
            </form>
        </flux:card>
    @endif
</div>
