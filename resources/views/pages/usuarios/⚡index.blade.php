<?php

use App\Models\ActivityLog;
use App\Models\Sucursal;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

new #[Title('Usuarios')] class extends Component {
    public bool $openCreate = false;

    public bool $openEdit = false;

    public ?int $editId = null;

    public array $form = [
        'name' => '',
        'email' => '',
        'sucursal_id' => '',
        'rol' => '',
        'password' => '',
        'password_confirmation' => '',
    ];

    public string $buscar = '';

    public string $rolFiltro = '';

    /** @var 'todos'|'activos'|'deshabilitados' */
    public string $filtro = 'todos';

    #[Computed]
    public function usuarios()
    {
        return User::with('roles', 'sucursal')->orderBy('name')->get();
    }

    #[Computed]
    public function visibles()
    {
        $texto = mb_strtolower(trim($this->buscar));

        return $this->usuarios
            ->when($this->filtro === 'activos', fn ($usuarios) => $usuarios->where('activo', true))
            ->when($this->filtro === 'deshabilitados', fn ($usuarios) => $usuarios->where('activo', false))
            ->when($this->rolFiltro !== '', fn ($usuarios) => $usuarios->filter(
                fn ($usuario) => $usuario->roles->contains('name', $this->rolFiltro)
            ))
            ->when($texto !== '', fn ($usuarios) => $usuarios->filter(
                fn ($usuario) => str_contains(mb_strtolower($usuario->name.' '.$usuario->email.' '.$usuario->sucursal?->name), $texto)
            ))
            ->values();
    }

    public function limpiarFiltros(): void
    {
        $this->reset(['buscar', 'rolFiltro', 'filtro']);
    }

    #[Computed]
    public function roles()
    {
        return Role::query()
            ->when(! Auth::user()->hasRole('Super Admin'), fn ($q) => $q->where('name', '!=', 'Super Admin'))
            ->get();
    }

    #[Computed]
    public function sucursales()
    {
        return Sucursal::orderBy('name')->get();
    }

    private function resetForm(): void
    {
        $this->form = ['name' => '', 'email' => '', 'sucursal_id' => '', 'rol' => '', 'password' => '', 'password_confirmation' => ''];
    }

    public function abrirCrear(): void
    {
        $this->resetForm();
        $this->openCreate = true;
    }

    public function generarPassword(): void
    {
        $password = Str::password(12);
        $this->form['password'] = $password;
        $this->form['password_confirmation'] = $password;
    }

    private function rolesPermitidos(): array
    {
        return $this->roles->pluck('name')->all();
    }

    public function crear(): void
    {
        $this->validate([
            'form.name' => ['required', 'string'],
            'form.email' => ['required', 'email', 'unique:users,email'],
            'form.sucursal_id' => ['nullable', 'integer'],
            'form.rol' => ['required', Rule::in($this->rolesPermitidos())],
            'form.password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $user = User::create([
                'name' => $this->form['name'],
                'email' => $this->form['email'],
                'sucursal_id' => $this->form['sucursal_id'] ?: null,
                'password' => $this->form['password'],
            ]);

            $user->assignRole($this->form['rol']);

            ActivityLog::log("creó el usuario \"{$user->name}\"", $user);

            $this->openCreate = false;
            unset($this->usuarios);
            Flux::toast(variant: 'success', text: 'Usuario registrado con éxito.');
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: 'Error al registrar el usuario.');
        }
    }

    public function abrirEditar(int $userId): void
    {
        $usuario = User::with('roles')->findOrFail($userId);

        $this->editId = $usuario->id;
        $this->form = [
            'name' => $usuario->name,
            'email' => $usuario->email,
            'sucursal_id' => $usuario->sucursal_id ?? '',
            'rol' => $usuario->roles->first()?->name ?? '',
            'password' => '',
            'password_confirmation' => '',
        ];
        $this->openEdit = true;
    }

    public function generarPasswordEdicion(): void
    {
        $password = Str::password(12);
        $this->form['password'] = $password;
        $this->form['password_confirmation'] = $password;
    }

    public function actualizar(): void
    {
        $reglas = [
            'form.name' => ['required', 'string'],
            'form.email' => ['required', 'email', 'unique:users,email,'.$this->editId],
            'form.sucursal_id' => ['nullable', 'integer'],
            'form.rol' => ['required', Rule::in($this->rolesPermitidos())],
        ];

        if (filled($this->form['password'])) {
            $reglas['form.password'] = ['string', 'min:8', 'confirmed'];
        }

        $this->validate($reglas);

        try {
            $usuario = User::findOrFail($this->editId);

            $datos = [
                'name' => $this->form['name'],
                'email' => $this->form['email'],
                'sucursal_id' => $this->form['sucursal_id'] ?: null,
            ];

            if (filled($this->form['password'])) {
                $datos['password'] = $this->form['password'];
            }

            $usuario->update($datos);
            $usuario->syncRoles([$this->form['rol']]);

            ActivityLog::log("editó el usuario \"{$usuario->name}\"", $usuario);

            $this->openEdit = false;
            $this->reset(['editId', 'form']);
            unset($this->usuarios);
            Flux::toast(variant: 'success', text: 'Usuario actualizado con éxito.');
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: 'Error al actualizar el usuario.');
        }
    }

    public function toggleActivo(int $userId): void
    {
        if ($userId === Auth::id()) {
            Flux::toast(variant: 'warning', text: 'No puedes deshabilitar tu propio usuario.');

            return;
        }

        $usuario = User::find($userId);

        if (! $usuario) {
            Flux::toast(variant: 'warning', text: 'Usuario no encontrado.');

            return;
        }

        $usuario->update(['activo' => ! $usuario->activo]);

        ActivityLog::log(
            ($usuario->activo ? 'habilitó' : 'deshabilitó')." al usuario \"{$usuario->name}\"",
            $usuario,
        );

        Flux::toast(variant: 'success', text: $usuario->activo ? 'Usuario habilitado.' : 'Usuario deshabilitado.');
    }
}; ?>

<div class="space-y-6">
    <x-page-header icon="users" title="Usuarios" subtitle="Administradores y capturistas del sistema.">
        <flux:button variant="primary" icon="plus" wire:click="abrirCrear">Agregar usuario</flux:button>
    </x-page-header>

    {{-- RESUMEN --}}
    @php
        $totalUsuarios = $this->usuarios->count();
        $activos = $this->usuarios->where('activo', true)->count();
        $administradores = $this->usuarios->filter(fn ($usuario) => $usuario->roles->contains(fn ($rol) => in_array($rol->name, ['Admin', 'Super Admin'], true)))->count();
    @endphp
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4">
        <x-stat-card label="Usuarios" icon="users">{{ $totalUsuarios }}</x-stat-card>
        <x-stat-card label="Activos" icon="check-circle" tone="positive">{{ $activos }}</x-stat-card>
        <x-stat-card label="Administradores" icon="shield-check" class="col-span-2 sm:col-span-1">{{ $administradores }}</x-stat-card>
    </div>

    {{-- BÚSQUEDA Y FILTROS --}}
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <flux:input wire:model.live.debounce.250ms="buscar" icon="magnifying-glass" placeholder="Buscar por nombre, correo o sucursal..." class="lg:max-w-sm" />

        <div class="flex flex-wrap items-center gap-2">
            <flux:select wire:model.live="rolFiltro" size="sm" class="min-w-40">
                <flux:select.option value="">Todos los roles</flux:select.option>
                @foreach ($this->roles as $rol)
                    <flux:select.option value="{{ $rol->name }}">{{ $rol->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:radio.group wire:model.live="filtro" variant="segmented" size="sm">
                <flux:radio value="todos" label="Todos" />
                <flux:radio value="activos" label="Activos" />
                <flux:radio value="deshabilitados" label="Deshabilitados" />
            </flux:radio.group>
        </div>
    </div>

    {{-- USUARIOS --}}
    @if ($this->visibles->isEmpty())
        <div class="rounded-2xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
            <div class="mx-auto flex size-12 items-center justify-center rounded-xl bg-accent-soft text-accent-content">
                <flux:icon name="users" class="size-6" />
            </div>
            <p class="mt-3 font-medium text-zinc-900 dark:text-white">
                {{ $totalUsuarios === 0 ? 'Aún no hay usuarios registrados' : 'No encontramos usuarios con ese filtro' }}
            </p>
            <p class="mt-1 text-sm text-zinc-500">
                {{ $totalUsuarios === 0 ? 'Agrega el primero con el botón "Agregar usuario".' : 'Prueba con otra búsqueda o cambia los filtros.' }}
            </p>
            @if ($totalUsuarios > 0)
                <flux:button class="mt-4" size="sm" wire:click="limpiarFiltros">Limpiar filtros</flux:button>
            @endif
        </div>
    @else
        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-sm shadow-zinc-900/5 dark:border-zinc-700/70 dark:bg-zinc-900 dark:shadow-none">
            <div class="max-h-[32rem] overflow-auto">
                <table class="w-full text-left text-sm md:min-w-[640px]">
                    <thead>
                        <tr class="sticky top-0 z-10 border-b border-zinc-200/70 bg-zinc-50 text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-700/70 dark:bg-zinc-800">
                            <th class="px-3 py-3">Usuario</th>
                            <th class="hidden px-3 py-3 md:table-cell">Sucursal</th>
                            <th class="hidden px-3 py-3 md:table-cell">Rol</th>
                            <th class="hidden px-3 py-3 md:table-cell">Estado</th>
                            <th class="px-3 py-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->visibles as $usuario)
                            <tr
                                wire:key="usuario-{{ $usuario->id }}"
                                @class([
                                    'border-b border-zinc-100 last:border-b-0 hover:bg-zinc-50/80 dark:border-zinc-800 dark:hover:bg-white/5',
                                    'opacity-70' => ! $usuario->activo,
                                ])
                            >
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-3">
                                        <span class="flex size-9 flex-none items-center justify-center rounded-full bg-accent-soft text-sm font-semibold text-accent-content">
                                            {{ mb_strtoupper(mb_substr($usuario->name, 0, 1)) }}
                                        </span>
                                        <div class="min-w-0">
                                            <div class="font-semibold text-zinc-900 dark:text-white">{{ $usuario->name }}</div>
                                            <div class="break-all text-xs text-zinc-500">{{ $usuario->email }}</div>
                                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5 md:hidden">
                                                @if ($usuario->roles->isNotEmpty())
                                                    <span class="inline-flex rounded-full bg-accent-soft px-2 py-0.5 text-[11px] font-semibold text-accent-content">{{ $usuario->roles->first()->name }}</span>
                                                @endif
                                                <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $usuario->activo ? 'bg-positive-soft text-positive' : 'bg-zinc-100 text-zinc-500 dark:bg-white/10' }}">{{ $usuario->activo ? 'Activo' : 'Deshabilitado' }}</span>
                                                <span class="text-[11px] text-zinc-500">{{ $usuario->sucursal?->name ?? 'Sin sucursal' }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="hidden px-3 py-3 text-zinc-600 md:table-cell dark:text-zinc-400">{{ $usuario->sucursal?->name ?? 'Ninguna' }}</td>
                                <td class="hidden px-3 py-3 md:table-cell">
                                    @if ($usuario->roles->isNotEmpty())
                                        <span class="inline-flex rounded-full bg-accent-soft px-2.5 py-1 text-xs font-semibold text-accent-content">{{ $usuario->roles->first()->name }}</span>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </td>
                                <td class="hidden px-3 py-3 md:table-cell">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {{ $usuario->activo ? 'bg-positive-soft text-positive' : 'bg-zinc-100 text-zinc-500 dark:bg-white/10' }}">
                                        {{ $usuario->activo ? 'Activo' : 'Deshabilitado' }}
                                    </span>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex gap-1.5">
                                        <flux:button size="sm" icon="pencil-square" aria-label="Editar" wire:click="abrirEditar({{ $usuario->id }})"><span class="max-sm:hidden">Editar</span></flux:button>
                                        <x-usuario-toggle-button :usuario="$usuario" compact />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <flux:modal wire:model="openCreate" name="crear-usuario" class="max-w-lg">
        <div class="space-y-4">
            <x-modal-header icon="user-plus" title="Nuevo usuario" subtitle="Crea el acceso y asigna su rol." />
            <flux:input wire:model="form.name" label="Nombre" />
            <flux:input type="email" wire:model="form.email" label="Correo" />

            <flux:select wire:model="form.sucursal_id" label="Sucursal">
                <flux:select.option value="">Ninguna (Admin)</flux:select.option>
                @foreach ($this->sucursales as $sucursal)
                    <flux:select.option value="{{ $sucursal->id }}">{{ $sucursal->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="form.rol" label="Rol">
                <flux:select.option value="">Selecciona un rol</flux:select.option>
                @foreach ($this->roles as $rol)
                    <flux:select.option value="{{ $rol->name }}">{{ $rol->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex items-end gap-2">
                <flux:input field:class="flex-1" type="text" wire:model="form.password" label="Contraseña" />
                <flux:button variant="ghost" wire:click="generarPassword">Generar</flux:button>
            </div>
            <flux:input type="text" wire:model="form.password_confirmation" label="Confirmar contraseña" />

            <div class="flex justify-end gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="$set('openCreate', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="crear">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="openEdit" name="editar-usuario" class="max-w-lg">
        <div class="space-y-4">
            <x-modal-header icon="user" title="Editar usuario" subtitle="Actualiza sus datos, rol o contraseña." />
            <flux:input wire:model="form.name" label="Nombre" />
            <flux:input type="email" wire:model="form.email" label="Correo" />

            <flux:select wire:model="form.sucursal_id" label="Sucursal">
                <flux:select.option value="">Ninguna (Admin)</flux:select.option>
                @foreach ($this->sucursales as $sucursal)
                    <flux:select.option value="{{ $sucursal->id }}">{{ $sucursal->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="form.rol" label="Rol">
                <flux:select.option value="">Selecciona un rol</flux:select.option>
                @foreach ($this->roles as $rol)
                    <flux:select.option value="{{ $rol->name }}">{{ $rol->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex items-end gap-2">
                <flux:input field:class="flex-1" type="text" wire:model="form.password" label="Nueva contraseña (opcional)" />
                <flux:button variant="ghost" wire:click="generarPasswordEdicion">Generar</flux:button>
            </div>
            <flux:input type="text" wire:model="form.password_confirmation" label="Confirmar contraseña" />

            <div class="flex justify-end gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="$set('openEdit', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="actualizar">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
