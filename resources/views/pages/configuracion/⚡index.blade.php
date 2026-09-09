<?php

use App\Models\ActivityLog;
use App\Models\Configuracion;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Configuración')] class extends Component {
    public bool $iaCapturaHabilitada = false;

    public function mount(): void
    {
        $this->iaCapturaHabilitada = Configuracion::activa(Configuracion::IA_CAPTURA_HABILITADA, default: true);
    }

    public function updatedIaCapturaHabilitada(bool $valor): void
    {
        Configuracion::activar(Configuracion::IA_CAPTURA_HABILITADA, $valor);

        ActivityLog::log(($valor ? 'habilitó' : 'deshabilitó').' el llenado automático con IA (foto/PDF) para los capturistas');

        Flux::toast(
            variant: 'success',
            text: $valor ? 'Llenado con IA habilitado para todos los usuarios.' : 'Llenado con IA deshabilitado para todos los usuarios.',
        );
    }
}; ?>

<div class="space-y-6 max-w-2xl">
    <div class="flex items-center gap-2">
        <span class="w-[26px] h-[26px] rounded bg-accent-soft text-accent flex items-center justify-center flex-none">
            <flux:icon name="cog-6-tooth" class="w-3.5 h-3.5" />
        </span>
        <div>
            <flux:heading size="lg">Configuración</flux:heading>
            <flux:subheading>Ajustes generales del sistema, disponibles solo para Super Admin.</flux:subheading>
        </div>
    </div>

    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <div class="font-semibold text-zinc-900 dark:text-zinc-100">Llenado automático con foto o PDF (IA)</div>
                <p class="text-sm text-zinc-500 mt-1 max-w-md">
                    Cuando está habilitado, los capturistas ven la opción de subir una foto o PDF del formulario en "Registrar cuenta" para que el sistema intente llenar los datos automáticamente. Al deshabilitarlo, esa opción desaparece y solo queda la captura manual.
                </p>
            </div>
            <flux:switch wire:model.live="iaCapturaHabilitada" />
        </div>
    </div>
</div>
