@props([
    'icon',
    'title',
    'hint' => null,
])

<div {{ $attributes->class('flex flex-col items-center gap-1.5 px-6 py-12 text-center') }}>
    <span class="mb-1 flex size-12 items-center justify-center rounded-full bg-zinc-100 text-zinc-400 dark:bg-white/5 dark:text-zinc-500">
        <flux:icon :name="$icon" class="size-6" />
    </span>
    <p class="font-medium text-zinc-700 dark:text-zinc-200">{{ $title }}</p>
    @if ($hint)
        <p class="max-w-sm text-sm text-zinc-500 dark:text-zinc-400">{{ $hint }}</p>
    @endif
</div>
