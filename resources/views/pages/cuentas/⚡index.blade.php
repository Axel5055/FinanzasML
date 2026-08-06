<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cuentas')] class extends Component {
    //
}; ?>

<div class="space-y-6">
    <div class="flex items-center gap-2">
        <span class="w-[26px] h-[26px] rounded bg-accent-soft text-accent flex items-center justify-center flex-none">
            <flux:icon name="ticket" class="w-3.5 h-3.5" />
        </span>
        <div>
            <flux:heading size="lg">Cuentas registradas</flux:heading>
            <flux:subheading>{{ Auth::user()->hasRole('Admin') ? 'Todas las sucursales' : (Auth::user()->sucursal->name ?? '') }}</flux:subheading>
        </div>
    </div>

    <livewire:cuenta-table />
</div>
