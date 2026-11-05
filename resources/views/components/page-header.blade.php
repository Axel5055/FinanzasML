@props([
    'icon',
    'title',
    'subtitle' => null,
    'size' => 'lg',
])

@php
    $box = match ($size) {
        'sm' => 'size-9 rounded-lg',
        'md' => 'size-11 rounded-xl',
        default => 'size-14 rounded-2xl',
    };

    $iconSize = match ($size) {
        'sm' => 'size-4.5',
        'md' => 'size-5',
        default => 'size-7',
    };

    $titleClasses = match ($size) {
        'sm' => 'text-base font-semibold',
        'md' => 'text-lg font-semibold',
        default => 'text-2xl font-medium sm:text-3xl',
    };

    $subtitleClasses = $size === 'lg' ? 'text-sm sm:text-base' : 'text-sm';
    $tag = $size === 'lg' ? 'h1' : 'h2';
@endphp

<div {{ $attributes->class('flex flex-wrap items-center justify-between gap-x-4 gap-y-3') }}>
    <div class="flex min-w-0 items-center gap-3.5 sm:gap-4">
        <span class="flex {{ $box }} flex-none items-center justify-center bg-accent-soft text-accent-content">
            <flux:icon :name="$icon" class="{{ $iconSize }}" />
        </span>
        <div class="min-w-0">
            <{{ $tag }} class="{{ $titleClasses }} tracking-tight text-zinc-900 dark:text-white">{{ $title }}</{{ $tag }}>
            @if ($subtitle)
                <p class="{{ $subtitleClasses }} mt-0.5 text-zinc-500 dark:text-zinc-400">{{ $subtitle }}</p>
            @endif
        </div>
    </div>

    @if (! $slot->isEmpty())
        <div class="flex flex-wrap items-center gap-2">{{ $slot }}</div>
    @endif
</div>
