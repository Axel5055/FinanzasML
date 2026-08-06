<?php

use App\Models\ActivityLog;
use App\Models\Sucursal;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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

    #[Computed]
    public function usuarios()
    {
        return User::with('roles', 'sucursal')->orderBy('name')->get();
    }

    #[Computed]
    public function roles()
    {
        return Role::all();
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

    public function crear(): void
    {
        $this->validate([
            'form.name' => ['required', 'string'],
            'form.email' => ['required', 'email', 'unique:users,email'],
            'form.sucursal_id' => ['nullable', 'integer'],
            'form.rol' => ['required'],
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
            'form.rol' => ['required'],
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
    <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-2">
            <span class="w-[26px] h-[26px] rounded bg-accent-soft text-accent flex items-center justify-center flex-none">
                <flux:icon name="users" class="w-3.5 h-3.5" />
            </span>
            <div>
                <flux:heading size="lg">Usuarios</flux:heading>
                <flux:subheading>Administradores y capturistas del sistema.</flux:subheading>
            </div>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="abrirCrear">Agregar usuario</flux:button>
    </div>

    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 overflow-hidden">
        <div class="max-h-[28rem] overflow-y-auto overflow-x-auto">
            <table class="w-full table-fixed text-[13.5px] text-left min-w-[880px]">
                <colgroup>
                    <col class="w-[18%]">
                    <col class="w-[24%]">
                    <col class="w-[16%]">
                    <col class="w-[12%]">
                    <col class="w-[12%]">
                    <col class="w-[18%]">
                </colgroup>
                <thead>
                    <tr class="sticky top-0 z-10">
                        <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700">Nombre</th>
                        <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700">Correo</th>
                        <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700">Sucursal</th>
                        <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700">Rol</th>
                        <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700">Estado</th>
                        <th class="text-[10.5px] font-bold tracking-wider uppercase text-zinc-400 bg-white dark:bg-zinc-800 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->usuarios as $usuario)
                        <tr wire:key="usuario-{{ $usuario->id }}" class="border-b border-zinc-200 dark:border-zinc-700 last:border-b-0 hover:bg-zinc-50 dark:hover:bg-zinc-700/40">
                            <td class="px-4 py-2.5 align-top font-semibold text-zinc-900 dark:text-zinc-100 break-words">{{ $usuario->name }}</td>
                            <td class="px-4 py-2.5 align-top text-zinc-600 dark:text-zinc-400 break-words">{{ $usuario->email }}</td>
                            <td class="px-4 py-2.5 align-top text-zinc-600 dark:text-zinc-400 break-words">{{ $usuario->sucursal?->name ?? 'Ninguna' }}</td>
                            <td class="px-4 py-2.5 align-top text-zinc-600 dark:text-zinc-400 break-words">{{ $usuario->roles->first()?->name }}</td>
                            <td class="px-4 py-2.5 align-top">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-bold {{ $usuario->activo ? 'bg-positive-soft text-positive' : 'bg-zinc-100 dark:bg-white/10 text-zinc-500' }}">
                                    {{ $usuario->activo ? 'Activo' : 'Deshabilitado' }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 align-top">
                                <div class="flex flex-wrap gap-1.5">
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="abrirEditar({{ $usuario->id }})">Editar</flux:button>
                                    <flux:button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        :icon="$usuario->activo ? 'no-symbol' : 'check-circle'"
                                        :disabled="$usuario->id === auth()->id()"
                                        x-on:click.prevent="
                                            Swal.fire({
                                                icon: 'question',
                                                title: '{{ $usuario->activo ? '¿Deshabilitar este usuario?' : '¿Habilitar este usuario?' }}',
                                                text: '{{ $usuario->activo ? 'El usuario no podrá iniciar sesión, pero su historial no se verá afectado.' : 'El usuario podrá volver a iniciar sesión.' }}',
                                                showDenyButton: true,
                                                confirmButtonText: '{{ $usuario->activo ? 'Deshabilitar' : 'Habilitar' }}',
                                                denyButtonText: 'Cancelar',
                                            }).then((result) => {
                                                if (result.isConfirmed) {
                                                    $wire.toggleActivo({{ $usuario->id }}).catch((error) => {
                                                        Swal.fire({
                                                            icon: 'error',
                                                            title: 'Error',
                                                            text: error.message || 'Ocurrió un problema al actualizar el usuario.',
                                                            showConfirmButton: true,
                                                        });
                                                    });
                                                }
                                            })
                                        "
                                    >
                                        {{ $usuario->activo ? 'Deshabilitar' : 'Habilitar' }}
                                    </flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 text-center text-zinc-400 text-sm py-10">Sin usuarios registrados.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <flux:modal wire:model="openCreate" name="crear-usuario" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">Nuevo usuario</flux:heading>
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

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('openCreate', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="crear">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="openEdit" name="editar-usuario" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">Editar usuario</flux:heading>
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

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('openEdit', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="actualizar">Guardar</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
