@props([
    'icon',
    'title',
    'subtitle' => null,
    'totales' => [],
])

<div {{ $attributes->class('overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-sm shadow-zinc-900/5 dark:border-zinc-700/70 dark:bg-zinc-900 dark:shadow-none') }}>
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200/70 px-4 py-4 sm:px-5 dark:border-zinc-700/70">
        <div class="flex min-w-0 items-center gap-3">
            <span class="flex size-10 flex-none items-center justify-center rounded-xl bg-accent-soft text-accent-content">
                <flux:icon :name="$icon" class="size-5" />
            </span>
            <div class="min-w-0">
                <h3 class="text-base font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $title }}</h3>
                @if ($subtitle)
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $subtitle }}</p>
                @endif
            </div>
        </div>

        @isset($action)
            <div class="flex-none">{{ $action }}</div>
        @endisset
    </div>

    {{ $slot }}

    @if ($totales !== [])
        <div class="flex flex-wrap items-baseline justify-end gap-x-6 gap-y-1 border-t border-zinc-200/70 bg-zinc-50 px-4 py-3 sm:px-5 dark:border-zinc-700/70 dark:bg-zinc-800/60">
            @foreach ($totales as $etiqueta => $valor)
                <span class="flex items-baseline gap-2">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500">{{ $etiqueta }}</span>
                    <span class="text-sm font-bold tabular-nums text-accent">{{ $valor }}</span>
                </span>
            @endforeach
        </div>
    @endif
</div>
