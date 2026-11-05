@props([
    'icon',
    'title',
    'subtitle' => null,
])

<div {{ $attributes->class('flex items-center gap-3.5 border-b border-zinc-100 pb-4 pe-8 dark:border-zinc-800') }}>
    <span class="flex size-11 flex-none items-center justify-center rounded-xl bg-accent-soft text-accent-content">
        <flux:icon :name="$icon" class="size-5" />
    </span>
    <div class="min-w-0">
        <h2 class="text-lg font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $title }}</h2>
        @if ($subtitle)
            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $subtitle }}</p>
        @endif
    </div>
</div>
