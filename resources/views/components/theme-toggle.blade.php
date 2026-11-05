@props([
    'align' => 'end',
])

<flux:dropdown x-data position="bottom" :align="$align">
    <flux:button
        {{ $attributes }}
        variant="ghost"
        size="sm"
        square
        aria-label="{{ __('Tema') }}"
        title="{{ __('Tema') }}"
        data-test="theme-toggle"
    >
        <flux:icon.moon variant="mini" x-show="! $flux.dark" x-cloak />
        <flux:icon.sun variant="mini" x-show="$flux.dark" x-cloak />
    </flux:button>

    <flux:menu class="min-w-36">
        <flux:menu.radio.group x-model="$flux.appearance">
            <flux:menu.radio value="light">Claro</flux:menu.radio>
            <flux:menu.radio value="dark">Oscuro</flux:menu.radio>
            <flux:menu.radio value="system">Automático</flux:menu.radio>
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
