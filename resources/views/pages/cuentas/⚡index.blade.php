<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cuentas')] class extends Component {
    //
}; ?>

<div class="space-y-6">
    @php
        $esAdmin = Auth::user()->hasAnyRole(['Admin', 'Super Admin']);
        $alcance = $esAdmin ? 'todas las sucursales' : (Auth::user()->sucursal->name ?? 'tu sucursal');
    @endphp
    <x-page-header icon="banknotes" title="Cuentas" :subtitle="'Consulta y administra las cuentas registradas de '.$alcance.'.'">
        <flux:button
            variant="primary"
            icon="plus"
            :href="$esAdmin ? route('admin.registrar.index') : route('capturista.registrar.index')"
            wire:navigate
        >
            Registrar cuenta
        </flux:button>
    </x-page-header>

    <div class="rounded-2xl border border-zinc-200/70 bg-white p-3 shadow-sm shadow-zinc-900/5 sm:p-5 dark:border-zinc-700/70 dark:bg-zinc-900 dark:shadow-none">
        <livewire:cuenta-table />
    </div>
</div>
