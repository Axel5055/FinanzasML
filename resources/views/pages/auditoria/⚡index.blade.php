<?php

use App\Models\ActivityLog;
use App\Models\Cuenta;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Auditoría')] class extends Component {
    use WithPagination;

    private const MODULOS = [
        'cuentas' => ['etiqueta' => 'Cuentas', 'clase' => Cuenta::class],
        'sucursales' => ['etiqueta' => 'Sucursales', 'clase' => Sucursal::class],
        'productos' => ['etiqueta' => 'Productos', 'clase' => Producto::class],
        'usuarios' => ['etiqueta' => 'Usuarios', 'clase' => User::class],
    ];

    private const SESIONES = ['inició sesión', 'cerró sesión'];

    public string $buscar = '';

    public string $usuarioFiltro = '';

    public string $moduloFiltro = '';

    public string $desde = '';

    public string $hasta = '';

    public int $porPagina = 25;

    public function updated(string $propiedad): void
    {
        if (! str_starts_with($propiedad, 'paginators')) {
            $this->resetPage();
        }
    }

    public function updatedPorPagina(): void
    {
        if (! in_array($this->porPagina, [25, 50, 100], true)) {
            $this->porPagina = 25;
        }
    }

    public function limpiarFiltros(): void
    {
        $this->reset(['buscar', 'usuarioFiltro', 'moduloFiltro', 'desde', 'hasta']);
        $this->resetPage();
    }

    /**
     * @return Builder<ActivityLog>
     */
    private function consulta(): Builder
    {
        $texto = trim($this->buscar);

        return ActivityLog::query()
            ->with('user.roles')
            ->when($this->usuarioFiltro !== '', fn (Builder $q) => $q->where('user_id', (int) $this->usuarioFiltro))
            ->when($this->moduloFiltro === 'sesiones', fn (Builder $q) => $q->whereIn('description', self::SESIONES))
            ->when($this->moduloFiltro === 'general', fn (Builder $q) => $q->whereNull('subject_type')->whereNotIn('description', self::SESIONES))
            ->when(isset(self::MODULOS[$this->moduloFiltro]), fn (Builder $q) => $q->where('subject_type', self::MODULOS[$this->moduloFiltro]['clase']))
            ->when($this->desde !== '', fn (Builder $q) => $q->where('created_at', '>=', \App\Actions\Fechas\DiaDeNegocio::inicioUtc(\Carbon\CarbonImmutable::parse($this->desde, \App\Actions\Fechas\DiaDeNegocio::zona()))))
            ->when($this->hasta !== '', fn (Builder $q) => $q->where('created_at', '<=', \App\Actions\Fechas\DiaDeNegocio::finUtc(\Carbon\CarbonImmutable::parse($this->hasta, \App\Actions\Fechas\DiaDeNegocio::zona()))))
            ->when($texto !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($texto) {
                $q->where('description', 'like', "%{$texto}%")
                    ->orWhere('ip_address', 'like', "%{$texto}%")
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$texto}%"));
            }))
            ->latest('created_at')
            ->latest('id');
    }

    #[Computed]
    public function registros()
    {
        return $this->consulta()->paginate($this->porPagina);
    }

    #[Computed]
    public function usuarios()
    {
        return User::orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return array{total: int, hoy: int, semana: int, usuariosHoy: int}
     */
    #[Computed]
    public function resumen(): array
    {
        return [
            'total' => ActivityLog::count(),
            'hoy' => ActivityLog::where('created_at', '>=', \App\Actions\Fechas\DiaDeNegocio::inicioUtc())->count(),
            'semana' => ActivityLog::where('created_at', '>=', \App\Actions\Fechas\DiaDeNegocio::inicioUtc(\App\Actions\Fechas\DiaDeNegocio::hoy()->subDays(7)))->count(),
            'usuariosHoy' => ActivityLog::where('created_at', '>=', \App\Actions\Fechas\DiaDeNegocio::inicioUtc())->whereNotNull('user_id')->distinct()->count('user_id'),
        ];
    }

    public function moduloDe(ActivityLog $registro): string
    {
        if (in_array($registro->description, self::SESIONES, true)) {
            return 'Sesiones';
        }

        foreach (self::MODULOS as $modulo) {
            if ($registro->subject_type === $modulo['clase']) {
                return $modulo['etiqueta'];
            }
        }

        return 'General';
    }

    public function exportar(): StreamedResponse
    {
        $nombre = 'auditoria-'.\App\Actions\Fechas\DiaDeNegocio::ahora()->format('Y-m-d_His').'.csv';

        return response()->streamDownload(function () {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, ['Fecha y hora', 'Usuario', 'Rol', 'Módulo', 'Movimiento', 'IP']);

            $this->consulta()->chunk(500, function ($registros) use ($salida) {
                foreach ($registros as $registro) {
                    fputcsv($salida, [
                        $registro->created_at->timezone(\App\Actions\Fechas\DiaDeNegocio::zona())->format('d/m/Y H:i:s'),
                        $registro->user->name ?? 'Usuario eliminado',
                        $registro->user?->roles->first()?->name ?? '',
                        $this->moduloDe($registro),
                        $registro->description,
                        $registro->ip_address,
                    ]);
                }
            });

            fclose($salida);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}; ?>

<div class="space-y-6">
    <x-page-header icon="clipboard-document-check" title="Auditoría" subtitle="Historial completo de movimientos: quién hizo qué y cuándo.">
        <flux:button icon="arrow-down-tray" wire:click="exportar">Exportar CSV</flux:button>
    </x-page-header>

    {{-- RESUMEN --}}
    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <x-stat-card label="Movimientos" icon="queue-list">
            {{ number_format($this->resumen['total']) }}
            <x-slot:footer>registrados en total</x-slot:footer>
        </x-stat-card>
        <x-stat-card label="Hoy" icon="clock">
            {{ number_format($this->resumen['hoy']) }}
            <x-slot:footer>movimientos de hoy</x-slot:footer>
        </x-stat-card>
        <x-stat-card label="Últimos 7 días" icon="calendar-days">
            {{ number_format($this->resumen['semana']) }}
            <x-slot:footer>movimientos de la semana</x-slot:footer>
        </x-stat-card>
        <x-stat-card label="Usuarios activos hoy" icon="users">
            {{ $this->resumen['usuariosHoy'] }}
            <x-slot:footer>con actividad hoy</x-slot:footer>
        </x-stat-card>
    </div>

    {{-- FILTROS --}}
    <div class="space-y-3 rounded-2xl border border-zinc-200/70 bg-white p-4 shadow-sm shadow-zinc-900/5 dark:border-zinc-700/70 dark:bg-zinc-900 dark:shadow-none">
        <flux:input wire:model.live.debounce.300ms="buscar" icon="magnifying-glass" placeholder="Buscar en el movimiento, usuario o IP..." />

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <flux:select wire:model.live="usuarioFiltro" size="sm" aria-label="Usuario">
                <flux:select.option value="">Todos los usuarios</flux:select.option>
                @foreach ($this->usuarios as $usuario)
                    <flux:select.option value="{{ $usuario->id }}">{{ $usuario->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="moduloFiltro" size="sm" aria-label="Módulo">
                <flux:select.option value="">Todos los módulos</flux:select.option>
                <flux:select.option value="cuentas">Cuentas</flux:select.option>
                <flux:select.option value="sucursales">Sucursales</flux:select.option>
                <flux:select.option value="productos">Productos</flux:select.option>
                <flux:select.option value="usuarios">Usuarios</flux:select.option>
                <flux:select.option value="sesiones">Sesiones</flux:select.option>
                <flux:select.option value="general">General</flux:select.option>
            </flux:select>

            <flux:input wire:model.live="desde" type="date" size="sm" aria-label="Desde" />
            <flux:input wire:model.live="hasta" type="date" size="sm" aria-label="Hasta" />

            <flux:button size="sm" icon="x-mark" wire:click="limpiarFiltros">Limpiar filtros</flux:button>
        </div>
    </div>

    {{-- HISTORIAL --}}
    @if ($this->registros->isEmpty())
        <div class="rounded-2xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
            <div class="mx-auto flex size-12 items-center justify-center rounded-xl bg-accent-soft text-accent-content">
                <flux:icon name="clipboard-document-check" class="size-6" />
            </div>
            <p class="mt-3 font-medium text-zinc-900 dark:text-white">No encontramos movimientos</p>
            <p class="mt-1 text-sm text-zinc-500">Prueba con otra búsqueda o ajusta las fechas y los filtros.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-sm shadow-zinc-900/5 dark:border-zinc-700/70 dark:bg-zinc-900 dark:shadow-none">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200/70 bg-zinc-50 text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-700/70 dark:bg-zinc-800">
                            <th class="whitespace-nowrap px-3 py-3">Fecha y hora</th>
                            <th class="px-3 py-3">Usuario</th>
                            <th class="px-3 py-3">Movimiento</th>
                            <th class="px-3 py-3">Módulo</th>
                            <th class="px-3 py-3">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->registros as $registro)
                            @php
                                $nombre = $registro->user->name ?? 'Usuario eliminado';
                                $rol = $registro->user?->roles->first()?->name;
                            @endphp
                            <tr wire:key="auditoria-{{ $registro->id }}" class="border-b border-zinc-100 align-top last:border-b-0 hover:bg-zinc-50/80 dark:border-zinc-800 dark:hover:bg-white/5">
                                <td class="whitespace-nowrap px-3 py-3">
                                    <div class="font-medium tabular-nums text-zinc-900 dark:text-white">{{ $registro->created_at->timezone(\App\Actions\Fechas\DiaDeNegocio::zona())->format('d/m/Y') }}</div>
                                    <div class="text-xs tabular-nums text-zinc-500">{{ $registro->created_at->timezone(\App\Actions\Fechas\DiaDeNegocio::zona())->format('H:i:s') }} · {{ $registro->created_at->diffForHumans() }}</div>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <span class="flex size-8 flex-none items-center justify-center rounded-full bg-accent-soft text-sm font-semibold text-accent-content">
                                            {{ mb_strtoupper(mb_substr($nombre, 0, 1)) }}
                                        </span>
                                        <div class="min-w-0">
                                            <div class="font-semibold text-zinc-900 dark:text-white">{{ $nombre }}</div>
                                            @if ($rol)
                                                <div class="whitespace-nowrap text-xs text-zinc-500">{{ $rol }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="min-w-[240px] px-3 py-3 text-zinc-700 dark:text-zinc-300">{{ $registro->description }}</td>
                                <td class="px-3 py-3">
                                    <span class="inline-flex rounded-full bg-accent-soft px-2.5 py-1 text-xs font-semibold text-accent-content">{{ $this->moduloDe($registro) }}</span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs tabular-nums text-zinc-500">{{ $registro->ip_address ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-3 border-t border-zinc-200/70 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-700/70">
                <div class="flex items-center gap-3 text-sm text-zinc-500">
                    <span>
                        Mostrando {{ $this->registros->firstItem() }}–{{ $this->registros->lastItem() }}
                        de {{ number_format($this->registros->total()) }}
                    </span>
                    <flux:select wire:model.live="porPagina" size="sm" class="w-28" aria-label="Registros por página">
                        <flux:select.option value="25">25 por pág.</flux:select.option>
                        <flux:select.option value="50">50 por pág.</flux:select.option>
                        <flux:select.option value="100">100 por pág.</flux:select.option>
                    </flux:select>
                </div>
                @if ($this->registros->hasPages())
                    <div>{{ $this->registros->links() }}</div>
                @endif
            </div>
        </div>
    @endif
</div>
