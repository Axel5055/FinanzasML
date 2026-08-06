<?php

use App\Models\ActivityLog;
use App\Models\Direccion;
use App\Models\Sucursal;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sucursales')] class extends Component {
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
        return Sucursal::query()->orderBy('name')->get();
    }

    private function reglas(bool $esCreacion): array
    {
        return [
            'form.name' => $esCreacion
                ? ['required', 'string', 'unique:sucursales,name']
                : ['required', 'string'],
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
            DB::transaction(function () {
                $direccion = Direccion::create([
                    'codigo_postal' => $this->form['codigo_postal'],
                    'colonia' => $this->form['colonia'],
                    'estado' => $this->form['estado'],
                    'numero_interior' => $this->form['numero_interior'],
                    'numero_exterior' => $this->form['numero_exterior'],
                    'calle' => $this->form['calle'],
                ]);

                Sucursal::create([
                    'name' => $this->form['name'],
                    'direccion_id' => $direccion->id,
                ]);
            });

            ActivityLog::log("creó la sucursal \"{$this->form['name']}\"");

            $this->openCreate = false;
            Flux::toast(variant: 'success', text: 'Sucursal registrada con éxito.');
        } catch (\Throwable $e) {
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
    <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-2">
            <span class="w-[26px] h-[26px] rounded bg-accent-soft text-accent flex items-center justify-center flex-none">
                <flux:icon name="building-storefront" class="w-3.5 h-3.5" />
            </span>
            <div>
                <flux:heading size="lg">Sucursales</flux:heading>
                <flux:subheading>Direcciones y estado de cada sucursal.</flux:subheading>
            </div>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="abrirCrear">Agregar sucursal</flux:button>
    </div>

    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full table-fixed text-[13.5px] text-left min-w-[760px]">
                <colgroup>
                    <col class="w-[18%]">
                    <col class="w-[42%]">
                    <col class="w-[15%]">
                    <col class="w-[25%]">
                </colgroup>
                <thead>
                    <tr>
                        <th class="px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 text-[10.5px] font-bold tracking-wider uppercase text-zinc-400">Nombre</th>
                        <th class="px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 text-[10.5px] font-bold tracking-wider uppercase text-zinc-400">Dirección</th>
                        <th class="px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 text-[10.5px] font-bold tracking-wider uppercase text-zinc-400">Estado</th>
                        <th class="px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 text-[10.5px] font-bold tracking-wider uppercase text-zinc-400">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->sucursales as $sucursal)
                        <tr wire:key="sucursal-{{ $sucursal->id }}" class="border-b border-zinc-200 dark:border-zinc-700 last:border-b-0 hover:bg-zinc-50 dark:hover:bg-zinc-700/40">
                            <td class="px-4 py-3 align-top font-semibold text-zinc-900 dark:text-zinc-100 break-words">{{ $sucursal->name }}</td>
                            <td class="px-4 py-3 align-top text-zinc-600 dark:text-zinc-400 whitespace-normal break-words">{{ $sucursal->direccion?->direccion_completa }}</td>
                            <td class="px-4 py-3 align-top">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-bold {{ $sucursal->activo ? 'bg-positive-soft text-positive' : 'bg-zinc-100 dark:bg-white/10 text-zinc-500' }}">
                                    {{ $sucursal->activo ? 'Activa' : 'Inactiva' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex flex-wrap gap-1.5">
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="abrirEditar({{ $sucursal->id }})">Editar</flux:button>
                                    <flux:button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        :icon="$sucursal->activo ? 'no-symbol' : 'check-circle'"
                                        x-on:click.prevent="
                                            Swal.fire({
                                                icon: 'question',
                                                title: '{{ $sucursal->activo ? '¿Deshabilitar esta sucursal?' : '¿Habilitar esta sucursal?' }}',
                                                text: '{{ $sucursal->activo ? 'Dejará de aparecer en el select para registrar cuentas nuevas. No se elimina ni afecta el historial ya registrado.' : 'La sucursal volverá a estar disponible para registrar cuentas.' }}',
                                                showDenyButton: true,
                                                confirmButtonText: '{{ $sucursal->activo ? 'Deshabilitar' : 'Habilitar' }}',
                                                denyButtonText: 'Cancelar',
                                            }).then((result) => {
                                                if (result.isConfirmed) {
                                                    $wire.toggleActivo({{ $sucursal->id }}).catch((error) => {
                                                        Swal.fire({
                                                            icon: 'error',
                                                            title: 'Error',
                                                            text: error.message || 'Ocurrió un problema al actualizar la sucursal.',
                                                            showConfirmButton: true,
                                                        });
                                                    });
                                                }
                                            })
                                        "
                                    >
                                        {{ $sucursal->activo ? 'Deshabilitar' : 'Habilitar' }}
                                    </flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 text-center text-zinc-400 text-sm py-10">Sin sucursales registradas.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <flux:modal wire:model="openCreate" name="crear-sucursal" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">Nueva sucursal</flux:heading>
            <flux:input wire:model="form.name" label="Nombre" />
            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="form.calle" label="Calle" />
                <flux:input wire:model="form.codigo_postal" label="Código postal" maxlength="5" />
                <flux:input wire:model="form.numero_exterior" label="Número exterior" />
                <flux:input wire:model="form.numero_interior" label="Número interior" />
                <flux:input wire:model="form.colonia" label="Colonia" />
                <flux:input wire:model="form.estado" label="Estado" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('openCreate', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="crear">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="openEdit" name="editar-sucursal" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">Editar sucursal</flux:heading>
            <flux:input wire:model="form.name" label="Nombre" />
            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="form.calle" label="Calle" />
                <flux:input wire:model="form.codigo_postal" label="Código postal" maxlength="5" />
                <flux:input wire:model="form.numero_exterior" label="Número exterior" />
                <flux:input wire:model="form.numero_interior" label="Número interior" />
                <flux:input wire:model="form.colonia" label="Colonia" />
                <flux:input wire:model="form.estado" label="Estado" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('openEdit', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="actualizar">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
