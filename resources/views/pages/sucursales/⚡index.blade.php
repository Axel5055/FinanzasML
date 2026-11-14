<?php

use App\Models\ActivityLog;
use App\Models\Cuenta;
use App\Models\Direccion;
use App\Models\Sucursal;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sucursales')] class extends Component {
    public string $buscar = '';

    /** @var 'todas'|'activas'|'inactivas' */
    public string $filtro = 'todas';

    /** @var 'tarjetas'|'lista' */
    public string $vista = 'tarjetas';

    public bool $openCreate = false;

    public bool $openEdit = false;

    public ?int $editId = null;

    public array $form = [
        'name' => '',
        'codigo_postal' => '',
        'colonia' => '',
        'estado' => '',
        'numero_interior' => '',
        'numero_exterior' => '',
        'calle' => '',
    ];

    #[Computed]
    public function sucursales()
    {
        return Sucursal::query()->with('direccion')->orderBy('name')->get();
    }

    /**
     * Sucursales que cumplen la búsqueda y el filtro de estado elegidos.
     *
     * @return Collection<int, Sucursal>
     */
    #[Computed]
    public function visibles(): Collection
    {
        $termino = Str::lower(trim($this->buscar));

        return $this->sucursales
            ->when($this->filtro === 'activas', fn (Collection $c) => $c->where('activo', true))
            ->when($this->filtro === 'inactivas', fn (Collection $c) => $c->where('activo', false))
            ->when($termino !== '', fn (Collection $c) => $c->filter(
                fn (Sucursal $s) => Str::contains(Str::lower($s->name.' '.$s->direccion?->direccion_completa), $termino)
            ))
            ->values();
    }

    /**
     * Ventas y número de cuentas del mes en curso, por sucursal.
     *
     * @return array<int, object{ventas: string, cuentas: int}>
     */
    #[Computed]
    public function resumenMes(): array
    {
        return Cuenta::query()
            ->whereBetween('fecha_venta', [\App\Actions\Fechas\DiaDeNegocio::ahora()->startOfMonth()->toDateString(), \App\Actions\Fechas\DiaDeNegocio::ahora()->endOfMonth()->toDateString()])
            ->where('total_venta', '!=', 0)
            ->selectRaw('sucursal_id, SUM(total_venta) as ventas, COUNT(*) as cuentas')
            ->groupBy('sucursal_id')
            ->get()
            ->keyBy('sucursal_id')
            ->all();
    }

    /**
     * @return array<int, string> fecha de la última cuenta registrada por sucursal
     */
    #[Computed]
    public function ultimaCuenta(): array
    {
        return Cuenta::query()
            ->where('total_venta', '!=', 0)
            ->selectRaw('sucursal_id, MAX(fecha_venta) as ultima')
            ->groupBy('sucursal_id')
            ->pluck('ultima', 'sucursal_id')
            ->all();
    }

    public function cambiarVista(): void
    {
        $this->vista = $this->vista === 'tarjetas' ? 'lista' : 'tarjetas';
    }

    private function reglas(bool $esCreacion): array
    {
        return [
            'form.name' => $esCreacion
                ? ['required', 'string', 'unique:sucursales,name']
                : ['required', 'string', 'unique:sucursales,name,'.$this->editId],
            'form.codigo_postal' => ['required', 'string', 'max:5'],
            'form.colonia' => ['nullable', 'string'],
            'form.estado' => ['nullable', 'string'],
            'form.numero_interior' => ['nullable', 'string'],
            'form.numero_exterior' => ['nullable', 'string'],
            'form.calle' => ['nullable', 'string'],
        ];
    }

    public function abrirCrear(): void
    {
        $this->form = ['name' => '', 'codigo_postal' => '', 'colonia' => '', 'estado' => '', 'numero_interior' => '', 'numero_exterior' => '', 'calle' => ''];
        $this->openCreate = true;
    }

    public function crear(): void
    {
        $this->validate($this->reglas(esCreacion: true));

        try {
            $sucursal = DB::transaction(function () {
                $direccion = Direccion::create([
                    'codigo_postal' => $this->form['codigo_postal'],
                    'colonia' => $this->form['colonia'],
                    'estado' => $this->form['estado'],
                    'numero_interior' => $this->form['numero_interior'],
                    'numero_exterior' => $this->form['numero_exterior'],
                    'calle' => $this->form['calle'],
                ]);

                return Sucursal::create([
                    'name' => $this->form['name'],
                    'direccion_id' => $direccion->id,
                ]);
            });

            ActivityLog::log("creó la sucursal \"{$this->form['name']}\"", $sucursal);

            $this->openCreate = false;
            Flux::toast(variant: 'success', text: 'Sucursal registrada con éxito.');
        } catch (\Throwable $e) {
            logger()->error(__METHOD__, ['error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', text: 'Error al registrar la sucursal.');
        }
    }

    public function abrirEditar(int $sucursalId): void
    {
        $sucursal = Sucursal::with('direccion')->findOrFail($sucursalId);

        $this->editId = $sucursal->id;
        $this->form = [
            'name' => $sucursal->name,
            'codigo_postal' => $sucursal->direccion->codigo_postal,
            'colonia' => $sucursal->direccion->colonia,
            'estado' => $sucursal->direccion->estado,
            'numero_interior' => $sucursal->direccion->numero_interior,
            'numero_exterior' => $sucursal->direccion->numero_exterior,
            'calle' => $sucursal->direccion->calle,
        ];
        $this->openEdit = true;
    }

    public function actualizar(): void
    {
        $this->validate($this->reglas(esCreacion: false));

        try {
            $sucursal = Sucursal::with('direccion')->findOrFail($this->editId);

            $sucursal->update(['name' => $this->form['name']]);

            $sucursal->direccion->update([
                'codigo_postal' => $this->form['codigo_postal'],
                'colonia' => $this->form['colonia'],
                'estado' => $this->form['estado'],
                'numero_interior' => $this->form['numero_interior'],
                'numero_exterior' => $this->form['numero_exterior'],
                'calle' => $this->form['calle'],
            ]);

            ActivityLog::log("editó la sucursal \"{$sucursal->name}\"", $sucursal);

            $this->openEdit = false;
            $this->reset(['editId', 'form']);
            Flux::toast(variant: 'success', text: 'Sucursal actualizada con éxito.');
        } catch (\Throwable $e) {
            logger()->error(__METHOD__, ['error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', text: 'Error al actualizar la sucursal.');
        }
    }

    public function toggleActivo(int $sucursalId): void
    {
        $sucursal = Sucursal::find($sucursalId);

        if (! $sucursal) {
            Flux::toast(variant: 'warning', text: 'Sucursal no encontrada.');

            return;
        }

        $sucursal->update(['activo' => ! $sucursal->activo]);

        ActivityLog::log(
            ($sucursal->activo ? 'habilitó' : 'deshabilitó')." la sucursal \"{$sucursal->name}\"",
            $sucursal,
        );

        Flux::toast(variant: 'success', text: $sucursal->activo ? 'Sucursal habilitada.' : 'Sucursal deshabilitada.');
    }
}; ?>

<div class="space-y-6">
    <x-page-header icon="building-storefront" title="Sucursales" subtitle="Direcciones y estado de cada sucursal.">
        <flux:button variant="primary" icon="plus" wire:click="abrirCrear">Agregar sucursal</flux:button>
    </x-page-header>

    {{-- RESUMEN --}}
    @php
        $total = $this->sucursales->count();
        $activas = $this->sucursales->where('activo', true)->count();
    @endphp
    <div class="grid grid-cols-3 gap-3 sm:gap-4">
        <x-stat-card label="Sucursales" icon="building-storefront">{{ $total }}</x-stat-card>
        <x-stat-card label="Activas" icon="check-circle" tone="positive">{{ $activas }}</x-stat-card>
        <x-stat-card label="Inactivas" icon="no-symbol">{{ $total - $activas }}</x-stat-card>
    </div>

    {{-- BÚSQUEDA Y FILTRO --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <flux:input wire:model.live.debounce.250ms="buscar" icon="magnifying-glass" placeholder="Buscar por nombre o dirección..." class="sm:max-w-sm" />

        <div class="flex items-center gap-2">
            <flux:radio.group wire:model.live="filtro" variant="segmented" size="sm">
                <flux:radio value="todas" label="Todas" />
                <flux:radio value="activas" label="Activas" />
                <flux:radio value="inactivas" label="Inactivas" />
            </flux:radio.group>

            <flux:button
                wire:click="cambiarVista"
                size="sm"
                :icon="$vista === 'tarjetas' ? 'list-bullet' : 'squares-2x2'"
                :aria-label="$vista === 'tarjetas' ? 'Cambiar a vista en lista' : 'Cambiar a vista en tarjetas'"
                :title="$vista === 'tarjetas' ? 'Cambiar a vista en lista' : 'Cambiar a vista en tarjetas'"
            >
                {{ $vista === 'tarjetas' ? 'Lista' : 'Tarjetas' }}
            </flux:button>
        </div>
    </div>

    {{-- SUCURSALES --}}
    @if ($this->visibles->isEmpty())
        <div class="rounded-2xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
            <div class="mx-auto flex size-12 items-center justify-center rounded-xl bg-accent-soft text-accent-content">
                <flux:icon name="building-storefront" class="size-6" />
            </div>
            <p class="mt-3 font-medium text-zinc-900 dark:text-white">
                {{ $this->sucursales->isEmpty() ? 'Aún no hay sucursales registradas' : 'No encontramos sucursales con ese filtro' }}
            </p>
            <p class="mt-1 text-sm text-zinc-500">
                {{ $this->sucursales->isEmpty() ? 'Agrega la primera con el botón "Agregar sucursal".' : 'Prueba con otra búsqueda o cambia el estado.' }}
            </p>
        </div>
    @elseif ($vista === 'lista')
        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-sm shadow-zinc-900/5 dark:border-zinc-700/70 dark:bg-zinc-900 dark:shadow-none">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200/70 bg-zinc-50 text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-700/70 dark:bg-zinc-800">
                            <th class="px-3 py-3">Sucursal</th>
                            <th class="px-3 py-3">Dirección</th>
                            <th class="px-3 py-3">Estado</th>
                            <th class="whitespace-nowrap px-3 py-3 text-right">Ventas del mes</th>
                            <th class="hidden px-3 py-3 text-right xl:table-cell">Cuentas</th>
                            <th class="hidden whitespace-nowrap px-3 py-3 xl:table-cell">Última cuenta</th>
                            <th class="px-3 py-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->visibles as $sucursal)
                            @php
                                $mes = $this->resumenMes[$sucursal->id] ?? null;
                                $ultima = $this->ultimaCuenta[$sucursal->id] ?? null;
                            @endphp
                            <tr
                                wire:key="fila-sucursal-{{ $sucursal->id }}"
                                @class([
                                    'border-b border-zinc-100 last:border-b-0 hover:bg-zinc-50/80 dark:border-zinc-800 dark:hover:bg-white/5',
                                    'opacity-70' => ! $sucursal->activo,
                                ])
                            >
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-3">
                                        <span class="flex size-8 flex-none items-center justify-center rounded-full bg-accent-soft text-sm font-semibold text-accent-content">
                                            {{ mb_strtoupper(mb_substr($sucursal->name, 0, 1)) }}
                                        </span>
                                        <span class="whitespace-nowrap font-semibold text-zinc-900 dark:text-white">{{ $sucursal->name }}</span>
                                    </div>
                                </td>
                                <td class="min-w-[200px] px-3 py-3 text-zinc-600 dark:text-zinc-400">{{ $sucursal->direccion?->direccion_completa ?: 'Sin dirección registrada' }}</td>
                                <td class="px-3 py-3">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {{ $sucursal->activo ? 'bg-positive-soft text-positive' : 'bg-zinc-100 text-zinc-500 dark:bg-white/10' }}">
                                        {{ $sucursal->activo ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-semibold tabular-nums text-zinc-900 dark:text-white">${{ number_format((float) ($mes->ventas ?? 0), 2) }}</td>
                                <td class="hidden px-3 py-3 text-right tabular-nums text-zinc-900 dark:text-white xl:table-cell">{{ $mes->cuentas ?? 0 }}</td>
                                <td class="hidden whitespace-nowrap px-3 py-3 tabular-nums text-zinc-600 dark:text-zinc-400 xl:table-cell">{{ $ultima ? \Carbon\Carbon::parse($ultima)->format('d/m/Y') : '—' }}</td>
                                <td class="px-3 py-3">
                                    <div class="flex gap-1.5">
                                        <flux:button size="sm" icon="pencil-square" wire:click="abrirEditar({{ $sucursal->id }})">Editar</flux:button>
                                        <x-sucursal-toggle-button :sucursal="$sucursal" compact />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 2xl:grid-cols-3">
            @foreach ($this->visibles as $sucursal)
                @php
                    $mes = $this->resumenMes[$sucursal->id] ?? null;
                    $ultima = $this->ultimaCuenta[$sucursal->id] ?? null;
                @endphp
                <div
                    wire:key="sucursal-{{ $sucursal->id }}"
                    @class([
                        'flex flex-col gap-4 rounded-2xl border border-zinc-200/70 bg-white p-5 shadow-sm shadow-zinc-900/5 dark:border-zinc-700/70 dark:bg-zinc-900 dark:shadow-none',
                        'opacity-70' => ! $sucursal->activo,
                    ])
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex size-11 flex-none items-center justify-center rounded-xl bg-accent-soft text-lg font-semibold text-accent-content">
                                {{ mb_strtoupper(mb_substr($sucursal->name, 0, 1)) }}
                            </span>
                            <div class="min-w-0">
                                <h3 class="truncate text-base font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $sucursal->name }}</h3>
                                <span class="mt-0.5 inline-flex items-center gap-1.5 text-xs font-medium {{ $sucursal->activo ? 'text-positive' : 'text-zinc-500' }}">
                                    <span class="size-1.5 rounded-full {{ $sucursal->activo ? 'bg-positive' : 'bg-zinc-400' }}"></span>
                                    {{ $sucursal->activo ? 'Activa' : 'Inactiva' }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <p class="flex items-start gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                        <flux:icon name="map-pin" class="mt-0.5 size-4 flex-none text-zinc-400" />
                        <span class="break-words">{{ $sucursal->direccion?->direccion_completa ?: 'Sin dirección registrada' }}</span>
                    </p>

                    <div class="grid grid-cols-2 gap-3 rounded-xl bg-zinc-50 p-3 dark:bg-white/5">
                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Ventas del mes</div>
                            <div class="mt-0.5 font-semibold tabular-nums text-zinc-900 dark:text-white">${{ number_format((float) ($mes->ventas ?? 0), 2) }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Cuentas del mes</div>
                            <div class="mt-0.5 font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $mes->cuentas ?? 0 }}</div>
                        </div>
                        <div class="col-span-2 text-xs text-zinc-500">
                            {{ $ultima ? 'Última cuenta: '.\Carbon\Carbon::parse($ultima)->format('d/m/Y') : 'Aún sin cuentas registradas' }}
                        </div>
                    </div>

                    <div class="mt-auto flex flex-wrap gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                        <flux:button size="sm" icon="pencil-square" wire:click="abrirEditar({{ $sucursal->id }})">Editar</flux:button>
                        <x-sucursal-toggle-button :sucursal="$sucursal" />
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <flux:modal wire:model="openCreate" name="crear-sucursal" class="max-w-lg">
        <div class="space-y-4">
            <x-modal-header icon="building-storefront" title="Nueva sucursal" subtitle="Nombre y dirección de la sucursal." />
            <flux:input wire:model="form.name" label="Nombre" />
            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="form.calle" label="Calle" />
                <flux:input wire:model="form.codigo_postal" label="Código postal" maxlength="5" />
                <flux:input wire:model="form.numero_exterior" label="Número exterior" />
                <flux:input wire:model="form.numero_interior" label="Número interior" />
                <flux:input wire:model="form.colonia" label="Colonia" />
                <flux:input wire:model="form.estado" label="Estado" />
            </div>
            <div class="flex justify-end gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="$set('openCreate', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="crear">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="openEdit" name="editar-sucursal" class="max-w-lg">
        <div class="space-y-4">
            <x-modal-header icon="building-storefront" title="Editar sucursal" subtitle="Nombre y dirección de la sucursal." />
            <flux:input wire:model="form.name" label="Nombre" />
            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="form.calle" label="Calle" />
                <flux:input wire:model="form.codigo_postal" label="Código postal" maxlength="5" />
                <flux:input wire:model="form.numero_exterior" label="Número exterior" />
                <flux:input wire:model="form.numero_interior" label="Número interior" />
                <flux:input wire:model="form.colonia" label="Colonia" />
                <flux:input wire:model="form.estado" label="Estado" />
            </div>
            <div class="flex justify-end gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="$set('openEdit', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="actualizar">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
