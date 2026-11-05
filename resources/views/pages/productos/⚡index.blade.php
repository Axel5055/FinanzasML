<?php

use App\Models\ActivityLog;
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

    public string $buscar = '';

    public string $categoriaFiltro = '';

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

    #[Computed]
    public function visibles()
    {
        $texto = mb_strtolower(trim($this->buscar));

        return $this->productos
            ->when($this->categoriaFiltro !== '', fn ($productos) => $productos->where('categoria_id', (int) $this->categoriaFiltro))
            ->when($texto !== '', fn ($productos) => $productos->filter(
                fn ($producto) => str_contains(mb_strtolower($producto->name.' '.$producto->categoria?->name), $texto)
            ))
            ->values();
    }

    public function limpiarFiltros(): void
    {
        $this->reset(['buscar', 'categoriaFiltro']);
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
        ActivityLog::log('actualizó los precios maestros de los productos');
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

        $producto = Producto::create($this->form);
        ActivityLog::log("creó el producto \"{$producto->name}\"", $producto);

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
            ActivityLog::log("editó el producto \"{$producto->name}\"", $producto);

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
            ActivityLog::log("eliminó el producto \"{$producto->name}\"", $producto);

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
    <x-page-header icon="cube" title="Productos" subtitle="Catálogo de productos y precios maestros.">
        @if ($canManageCatalogo && $tab === 'productos')
            <flux:button variant="primary" icon="plus" wire:click="abrirCrear">Agregar producto</flux:button>
        @endif
    </x-page-header>

    {{-- RESUMEN --}}
    @php
        $totalProductos = $this->productos->count();
        $sinPrecio = $this->productos->whereNull('precio')->count();
    @endphp
    <div class="grid grid-cols-3 gap-3 sm:gap-4">
        <x-stat-card label="Productos" icon="cube">{{ $totalProductos }}</x-stat-card>
        <x-stat-card label="Categorías" icon="tag">{{ $this->categorias->count() }}</x-stat-card>
        <x-stat-card label="Sin precio" icon="exclamation-circle" :tone="$sinPrecio > 0 ? 'negative' : 'positive'">{{ $sinPrecio }}</x-stat-card>
    </div>

    {{-- PESTAÑAS --}}
    @if ($canManageCatalogo)
        <div class="inline-flex gap-1 rounded-xl bg-zinc-200/60 p-1 dark:bg-white/10">
            <button
                type="button"
                wire:click="cambiarTab('productos')"
                @class([
                    'rounded-lg px-4 py-1.5 text-sm font-medium transition',
                    'bg-white text-accent-content shadow-sm dark:bg-zinc-900' => $tab === 'productos',
                    'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' => $tab !== 'productos',
                ])
            >Productos</button>
            <button
                type="button"
                wire:click="cambiarTab('precios')"
                @class([
                    'rounded-lg px-4 py-1.5 text-sm font-medium transition',
                    'bg-white text-accent-content shadow-sm dark:bg-zinc-900' => $tab === 'precios',
                    'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' => $tab !== 'precios',
                ])
            >Precios</button>
        </div>
    @endif

    {{-- BÚSQUEDA Y FILTRO --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <flux:input wire:model.live.debounce.250ms="buscar" icon="magnifying-glass" placeholder="Buscar producto o categoría..." class="sm:max-w-sm" />

        <div class="flex items-center gap-2">
            <flux:select wire:model.live="categoriaFiltro" size="sm" class="min-w-44">
                <flux:select.option value="">Todas las categorías</flux:select.option>
                @foreach ($this->categorias as $categoria)
                    <flux:select.option value="{{ $categoria->id }}">{{ $categoria->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    @if ($canManageCatalogo && $tab === 'productos')
        {{-- PRODUCTOS --}}
        @if ($this->visibles->isEmpty())
            <div class="rounded-2xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
                <div class="mx-auto flex size-12 items-center justify-center rounded-xl bg-accent-soft text-accent-content">
                    <flux:icon name="cube" class="size-6" />
                </div>
                <p class="mt-3 font-medium text-zinc-900 dark:text-white">
                    {{ $totalProductos === 0 ? 'Aún no hay productos registrados' : 'No encontramos productos con ese filtro' }}
                </p>
                <p class="mt-1 text-sm text-zinc-500">
                    {{ $totalProductos === 0 ? 'Agrega el primero con el botón "Agregar producto".' : 'Prueba con otra búsqueda o cambia la categoría.' }}
                </p>
                @if ($totalProductos > 0)
                    <flux:button class="mt-4" size="sm" wire:click="limpiarFiltros">Limpiar filtros</flux:button>
                @endif
            </div>
        @else
            <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-sm shadow-zinc-900/5 dark:border-zinc-700/70 dark:bg-zinc-900 dark:shadow-none">
                <div class="max-h-[32rem] overflow-auto">
                    <table class="w-full min-w-[560px] text-left text-sm">
                        <thead>
                            <tr class="sticky top-0 z-10 border-b border-zinc-200/70 bg-zinc-50 text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-700/70 dark:bg-zinc-800">
                                <th class="px-4 py-3">Producto</th>
                                <th class="px-4 py-3">Categoría</th>
                                <th class="px-4 py-3 text-right">Precio</th>
                                <th class="px-4 py-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->visibles as $producto)
                                <tr wire:key="fila-producto-{{ $producto->id }}" class="border-b border-zinc-100 last:border-b-0 hover:bg-zinc-50/80 dark:border-zinc-800 dark:hover:bg-white/5">
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            <span class="flex size-8 flex-none items-center justify-center rounded-lg bg-accent-soft text-accent-content">
                                                <flux:icon name="cube" class="size-4" />
                                            </span>
                                            <span class="font-semibold text-zinc-900 dark:text-white">{{ $producto->name }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($producto->categoria)
                                            <span class="inline-flex rounded-full bg-accent-soft px-2.5 py-1 text-xs font-semibold text-accent-content">{{ $producto->categoria->name }}</span>
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums {{ $producto->precio === null ? 'text-zinc-400' : 'text-zinc-900 dark:text-white' }}">
                                        {{ $producto->precio === null ? 'Sin precio' : '$'.number_format((float) $producto->precio, 2) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex gap-1.5">
                                            <flux:button size="sm" icon="pencil-square" wire:click="abrirEditar({{ $producto->id }})">Editar</flux:button>
                                            <x-producto-delete-button :producto="$producto" compact />
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @else
        {{-- PRECIOS --}}
        @php
            $textoPrecios = mb_strtolower(trim($buscar));
            $categoriaPorProducto = $this->productos->pluck('categoria_id', 'id');
            $preciosVisibles = collect($precios)->filter(function ($precio) use ($textoPrecios, $categoriaPorProducto) {
                if ($this->categoriaFiltro !== '' && (int) ($categoriaPorProducto[$precio['id']] ?? 0) !== (int) $this->categoriaFiltro) {
                    return false;
                }

                return $textoPrecios === '' || str_contains(mb_strtolower($precio['name']), $textoPrecios);
            });
        @endphp
        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-sm shadow-zinc-900/5 dark:border-zinc-700/70 dark:bg-zinc-900 dark:shadow-none">
            <div class="flex flex-col gap-3 border-b border-zinc-200/70 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-700/70">
                <p class="text-sm text-zinc-600 dark:text-zinc-400">Precio maestro por producto. Se usa al presionar "Aplicar nuevos precios" en el registro de cuenta.</p>
                <flux:button size="sm" variant="primary" icon="check" wire:click="guardarPrecios">Guardar precios</flux:button>
            </div>
            <div class="max-h-[32rem] overflow-auto">
                <table class="w-full min-w-[420px] text-left text-sm">
                    <thead>
                        <tr class="sticky top-0 z-10 border-b border-zinc-200/70 bg-zinc-50 text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-700/70 dark:bg-zinc-800">
                            <th class="px-5 py-3">Producto</th>
                            <th class="w-48 px-5 py-3">Precio</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($preciosVisibles as $index => $precio)
                            <tr wire:key="precio-{{ $precio['id'] }}" class="border-b border-zinc-100 last:border-b-0 hover:bg-zinc-50/80 dark:border-zinc-800 dark:hover:bg-white/5">
                                <td class="px-5 py-2.5 font-semibold text-zinc-900 dark:text-white">{{ $precio['name'] }}</td>
                                <td class="px-5 py-2">
                                    <flux:input size="sm" type="number" step="0.001" icon="currency-dollar" wire:model="precios.{{ $index }}.precio" />
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="2" class="px-5 py-10 text-center text-sm text-zinc-400">No encontramos productos con ese filtro.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <flux:modal wire:model="openCreate" name="crear-producto" class="max-w-sm">
        <div class="space-y-4">
            <x-modal-header icon="cube" title="Nuevo producto" subtitle="Agrega un producto al catálogo." />
            <flux:input wire:model="form.name" label="Nombre" />
            <flux:select wire:model="form.categoria_id" label="Categoría">
                <flux:select.option value="">Selecciona una categoría</flux:select.option>
                @foreach ($this->categorias as $categoria)
                    <flux:select.option value="{{ $categoria->id }}">{{ $categoria->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="$set('openCreate', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="crear">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="openEdit" name="editar-producto" class="max-w-sm">
        <div class="space-y-4">
            <x-modal-header icon="cube" title="Editar producto" subtitle="Modifica el nombre o la categoría." />
            <flux:input wire:model="form.name" label="Nombre" />
            <flux:select wire:model="form.categoria_id" label="Categoría">
                <flux:select.option value="">Selecciona una categoría</flux:select.option>
                @foreach ($this->categorias as $categoria)
                    <flux:select.option value="{{ $categoria->id }}">{{ $categoria->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="$set('openEdit', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="actualizar">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
