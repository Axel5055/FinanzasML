<?php

use App\Actions\Permisos\SeccionesDelAdmin;
use App\Models\ActivityLog;
use App\Models\Configuracion;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Configuración')] class extends Component {
    public bool $iaCapturaHabilitada = false;

    /** @var array<string, bool> */
    public array $seccionesAdmin = [];

    public function mount(SeccionesDelAdmin $secciones): void
    {
        $this->seccionesAdmin = $secciones->estado();
        $this->iaCapturaHabilitada = Configuracion::activa(Configuracion::IA_CAPTURA_HABILITADA, default: true);
    }

    public function updatedSeccionesAdmin(bool $valor, string $seccion, SeccionesDelAdmin $secciones): void
    {
        abort_unless(auth()->user()->can('admin.configuracion.index'), 403);

        $secciones->cambiar($seccion, $valor);

        $etiqueta = SeccionesDelAdmin::SECCIONES[$seccion]['etiqueta'];

        ActivityLog::log(($valor ? 'habilitó' : 'quitó').' el acceso a "'.$etiqueta.'" al rol Admin');

        Flux::toast(
            variant: 'success',
            text: $valor ? "El rol Admin ahora puede entrar a {$etiqueta}." : "El rol Admin ya no puede entrar a {$etiqueta}.",
        );
    }

    public function updatedIaCapturaHabilitada(bool $valor): void
    {
        abort_unless(auth()->user()->can('admin.configuracion.index'), 403);

        Configuracion::activar(Configuracion::IA_CAPTURA_HABILITADA, $valor);

        ActivityLog::log(($valor ? 'habilitó' : 'deshabilitó').' el llenado automático con IA (foto/PDF) para los capturistas');

        Flux::toast(
            variant: 'success',
            text: $valor ? 'Llenado con IA habilitado para todos los usuarios.' : 'Llenado con IA deshabilitado para todos los usuarios.',
        );
    }
}; ?>

<div class="max-w-3xl space-y-6">
    <x-page-header icon="cog-6-tooth" title="Configuración" subtitle="Ajustes generales del sistema, disponibles solo para Super Admin." />

    <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-sm shadow-zinc-900/5 dark:border-zinc-700/70 dark:bg-zinc-900 dark:shadow-none">
        <div class="border-b border-zinc-200/70 px-5 py-3 dark:border-zinc-700/70">
            <h2 class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Captura de cuentas</h2>
        </div>

        <div class="flex items-start gap-4 p-5">
            <span class="flex size-12 flex-none items-center justify-center rounded-xl bg-accent-soft text-accent">
                <flux:icon name="sparkles" class="size-6" />
            </span>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="font-semibold text-zinc-900 dark:text-white">Llenado automático con foto o PDF (IA)</h3>
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $iaCapturaHabilitada ? 'bg-positive-soft text-positive' : 'bg-zinc-100 text-zinc-500 dark:bg-white/10' }}">
                        {{ $iaCapturaHabilitada ? 'Habilitado' : 'Deshabilitado' }}
                    </span>
                </div>
                <p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-400">
                    Cuando está habilitado, los capturistas ven la opción de subir una foto o PDF del formulario en "Registrar cuenta" para que el sistema intente llenar los datos automáticamente.
                </p>
                <p class="mt-1.5 text-sm text-zinc-500">
                    Al deshabilitarlo, esa opción desaparece y solo queda la captura manual.
                </p>
            </div>

            <flux:switch wire:model.live="iaCapturaHabilitada" aria-label="Llenado automático con IA" />
        </div>
    </div>

    <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-sm shadow-zinc-900/5 dark:border-zinc-700/70 dark:bg-zinc-900 dark:shadow-none">
        <div class="border-b border-zinc-200/70 px-5 py-3 dark:border-zinc-700/70">
            <h2 class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Accesos del rol Admin</h2>
        </div>

        <div class="p-5 pb-3">
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Elige a qué secciones puede entrar un Admin. Si quitas una, desaparece de su menú y no podrá abrirla aunque escriba la dirección. El Super Admin siempre tiene acceso a todo.
            </p>
        </div>

        <div class="divide-y divide-zinc-100 px-5 pb-2 dark:divide-zinc-800">
            @foreach (SeccionesDelAdmin::SECCIONES as $clave => $seccion)
                <div class="flex items-center gap-4 py-3.5" wire:key="seccion-{{ $clave }}">
                    <span class="flex size-10 flex-none items-center justify-center rounded-xl bg-accent-soft text-accent">
                        <flux:icon :name="$seccion['icono']" class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold text-zinc-900 dark:text-white">{{ $seccion['etiqueta'] }}</div>
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $seccion['descripcion'] }}</div>
                    </div>
                    <span class="hidden rounded-full px-2.5 py-0.5 text-xs font-semibold sm:inline-flex {{ $seccionesAdmin[$clave] ? 'bg-positive-soft text-positive' : 'bg-zinc-100 text-zinc-500 dark:bg-white/10' }}">
                        {{ $seccionesAdmin[$clave] ? 'Con acceso' : 'Sin acceso' }}
                    </span>
                    <flux:switch wire:model.live="seccionesAdmin.{{ $clave }}" :aria-label="'Acceso de Admin a '.$seccion['etiqueta']" />
                </div>
            @endforeach
        </div>
    </div>
</div>
