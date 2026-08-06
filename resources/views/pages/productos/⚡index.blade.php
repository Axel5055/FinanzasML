<?php

use App\Models\Categoria;
use App\Models\Producto;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Productos')] class extends Component {
    public string $tab = 'productos';

    public bool $canManageCatalogo = false;

    public bool $openCreate = false;

    public bool $openEdit = false;

    public ?int $editId = null;

    public array $form = ['name' => '', 'categoria_id' => ''];

    public array $precios = [];

    public function mount(): void
    {
        $this->canManageCatalogo = auth()->user()->can('admin.productos.index');
        $this->tab = $this->canManageCatalogo ? 'productos' : 'precios';

        $this->cargarPrecios();
    }

    private function cargarPrecios(): void
    {
        $this->precios = Producto::orderBy('name')
            ->get(['id', 'name', 'precio'])
            ->map(fn ($producto) => ['id' => $producto->id, 'name' => $producto->name, 'precio' => $producto->precio])
            ->toArray();
    }

    #[Computed]
    public function productos()
    {
        return Producto::with('categoria')->orderBy('name')->get();
    }

    #[Computed]
    public function categorias()
    {
        return Categoria::orderBy('name')->get();
    }

    public function cambiarTab(string $tab): void
    {
        if ($tab === 'productos' && ! $this->canManageCatalogo) {
            return;
        }

        $this->tab = $tab;
    }

    public function guardarPrecios(): void
    {
        collect($this->precios)->each(function ($precio) {
            Producto::where('id', $precio['id'])->update([
                'precio' => $precio['precio'] !== '' && $precio['precio'] !== null ? $precio['precio'] : null,
            ]);
        });

        unset($this->productos);
        Flux::toast(variant: 'success', text: 'Precios actualizados con éxito.');
    }

    public function abrirCrear(): void
    {
        abort_unless($this->canManageCatalogo, 403);

        $this->form = ['name' => '', 'categoria_id' => ''];
        $this->openCreate = true;
    }

    public function crear(): void
    {
        abort_unless($this->canManageCatalogo, 403);

        $this->validate([
            'form.name' => ['required', 'string'],
            'form.categoria_id' => ['required', 'exists:categorias,id'],
        ]);

        Producto::create($this->form);

        $this->openCreate = false;
        $this->cargarPrecios();
        unset($this->productos);
        Flux::toast(variant: 'success', text: 'Producto registrado con éxito.');
    }

    public function abrirEditar(int $productoId): void
    {
        abort_unless($this->canManageCatalogo, 403);

        $producto = Producto::findOrFail($productoId);

        $this->editId = $producto->id;
        $this->form = ['name' => $producto->name, 'categoria_id' => $producto->categoria_id];
        $this->openEdit = true;
    }

    public function actualizar(): void
    {
        abort_unless($this->canManageCatalogo, 403);

        $this->validate([
            'form.name' => ['required', 'string'],
            'form.categoria_id' => ['required', 'exists:categorias,id'],
        ]);

        try {
            $producto = Producto::findOrFail($this->editId);
            $producto->update($this->form);

            $this->openEdit = false;
            $this->reset(['editId', 'form']);
            unset($this->productos);
            $this->cargarPrecios();
            Flux::toast(variant: 'success', text: 'Producto actualizado con éxito.');
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: 'Error al actualizar el producto.');
        }
    }

    public function eliminar(int $productoId): void
    {
        abort_unless($this->canManageCatalogo, 403);

        try {
            $producto = Producto::find($productoId);

            if (! $producto) {
                Flux::toast(variant: 'warning', text: 'Producto no encontrado.');

                return;
            }

            if ($producto->tieneRegistrosAsociados()) {
                Flux::toast(variant: 'warning', text: 'No se puede eliminar: el producto ya está relacionado con una cuenta o proceso.');

                return;
            }

            $producto->delete();

            unset($this->productos);
            $this->cargarPrecios();
            Flux::toast(variant: 'success', text: 'Producto eliminado con éxito.');
        } catch (\Throwable $e) {
            logger()->error('Error al eliminar producto', ['error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', text: 'No se puede eliminar el producto porque está asociado a otros registros.');
        }
    }
}; ?>

<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-2">
            <span class="w-[26px] h-[26px] rounded bg-accent-soft text-accent flex items-center justify-center flex-none">
                <flux:icon name="cube" class="w-3.5 h-3.5" />
            </span>
            <div>
                <flux:heading size="lg">Productos</flux:heading>
                <flux:subheading>Catálogo de productos y precios maestros.</flux:subheading>
            </div>
        </div>
        @if ($canManageCatalogo && $tab === 'productos')
            <flux:button variant="primary" icon="plus" wire:click="abrirCrear">Agregar producto</flux:button>
        @endif
    </div>

    @if ($canManageCatalogo)
        <div class="flex gap-2">
            <flux:button size="sm" :variant="$tab === 'productos' ? 'primary' : 'ghost'" wire:click="cambiarTab('productos')">Productos</flux:button>
            <flux:button size="sm" :variant="$tab === 'precios' ? 'primary' : 'ghost'" wire:click="cambiarTab('precios')">Precios</flux:button>
        </div>
    @endif

    @if ($canManageCatalogo && $tab === 'productos')
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 overflow-hidden">
            <div class="max-h-[28rem] overflow-y-auto overflow-x-auto">
                <table class="w-full table-fixed text-[13.5px] text-left min-w-[640px]">
                    <colgroup>
                        <col class="w-[38%]">
                        <col class="w-[32%]">
                        <col class="w-[30%]">
                    </colgroup>
                    <thead>
                        <tr class="sticky top-0 z-10">
                            <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700">Producto</th>
                            <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700">Categoría</th>
                            <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->productos as $producto)
                            <tr wire:key="producto-{{ $producto->id }}" class="border-b border-zinc-200 dark:border-zinc-700 last:border-b-0 hover:bg-zinc-50 dark:hover:bg-zinc-700/40">
                                <td class="px-4 py-2.5 align-top font-semibold text-zinc-900 dark:text-zinc-100 break-words">{{ $producto->name }}</td>
                                <td class="px-4 py-2.5 align-top text-zinc-600 dark:text-zinc-400 break-words">{{ $producto->categoria?->name }}</td>
                                <td class="px-4 py-2.5 align-top">
                                    <div class="flex flex-wrap gap-1.5">
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="abrirEditar({{ $producto->id }})">Editar</flux:button>
                                        <flux:button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            icon="trash"
                                            x-on:click.prevent="
                                                Swal.fire({
                                                    icon: 'question',
                                                    title: '¿Estás seguro de eliminar el producto?',
                                                    text: 'Una vez eliminado no se podrá recuperar.',
                                                    showDenyButton: true,
                                                    confirmButtonText: 'Eliminar',
                                                    denyButtonText: 'No eliminar',
                                                }).then((result) => {
                                                    if (result.isConfirmed) {
                                                        $wire.eliminar({{ $producto->id }}).catch((error) => {
                                                            Swal.fire({
                                                                icon: 'error',
                                                                title: 'Error al eliminar',
                                                                text: error.message || 'Ocurrió un problema al intentar eliminar el producto.',
                                                                showConfirmButton: true,
                                                            });
                                                        });
                                                    }
                                                })
                                            "
                                        >Eliminar</flux:button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-4 text-center text-zinc-400 text-sm py-10">Sin productos registrados.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 overflow-hidden">
            <div class="flex items-center justify-between gap-4 px-4 py-3 border-b border-zinc-200 dark:border-zinc-700">
                <flux:subheading>Precio maestro por producto (se usa al presionar "Aplicar nuevos precios" en el registro de cuenta).</flux:subheading>
                <flux:button size="sm" variant="primary" icon="check" wire:click="guardarPrecios">Guardar precios</flux:button>
            </div>
            <div class="max-h-[28rem] overflow-y-auto overflow-x-auto">
                <table class="w-full table-fixed text-[13.5px] text-left min-w-[420px]">
                    <colgroup>
                        <col class="w-[65%]">
                        <col class="w-[35%]">
                    </colgroup>
                    <thead>
                        <tr class="sticky top-0 z-10">
                            <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700">Producto</th>
                            <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700">Precio</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($precios as $index => $precio)
                            <tr wire:key="precio-{{ $precio['id'] }}" class="border-b border-zinc-200 dark:border-zinc-700 last:border-b-0 hover:bg-zinc-50 dark:hover:bg-zinc-700/40">
                                <td class="px-4 py-2 align-top font-semibold text-zinc-900 dark:text-zinc-100 break-words">{{ $precio['name'] }}</td>
                                <td class="px-4 py-2 align-top">
                                    <flux:input size="sm" type="number" step="0.001" icon="currency-dollar" wire:model="precios.{{ $index }}.precio" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <flux:modal wire:model="openCreate" name="crear-producto" class="max-w-sm">
        <div class="space-y-4">
            <flux:heading size="lg">Nuevo producto</flux:heading>
            <flux:input wire:model="form.name" label="Nombre" />
            <flux:select wire:model="form.categoria_id" label="Categoría">
                <flux:select.option value="">Selecciona una categoría</flux:select.option>
                @foreach ($this->categorias as $categoria)
                    <flux:select.option value="{{ $categoria->id }}">{{ $categoria->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('openCreate', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="crear">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="openEdit" name="editar-producto" class="max-w-sm">
        <div class="space-y-4">
            <flux:heading size="lg">Editar producto</flux:heading>
            <flux:input wire:model="form.name" label="Nombre" />
            <flux:select wire:model="form.categoria_id" label="Categoría">
                <flux:select.option value="">Selecciona una categoría</flux:select.option>
                @foreach ($this->categorias as $categoria)
                    <flux:select.option value="{{ $categoria->id }}">{{ $categoria->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('openEdit', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="actualizar">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
