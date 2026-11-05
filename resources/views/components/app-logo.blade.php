@props([
    'sidebar' => false,
])

<a {{ $attributes->class('flex min-w-0 items-center gap-3 rounded-xl py-1') }}>
    <img
        src="{{ asset('icons/icon-192.png') }}"
        alt="ML Grupo"
        class="size-11 flex-none rounded-xl bg-white object-contain p-0.5 shadow-sm"
    />
    <span class="grid min-w-0 leading-tight">
        <span class="truncate text-lg font-bold tracking-tight {{ $sidebar ? 'text-white' : 'text-zinc-900 dark:text-white' }}">ML Grupo</span>
        <span class="truncate text-[11px] font-medium uppercase tracking-[0.28em] {{ $sidebar ? 'text-zinc-400' : 'text-zinc-500 dark:text-zinc-400' }}">Comercial</span>
    </span>
</a>
