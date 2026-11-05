@props([
    'label',
    'icon',
    'tone' => 'neutral',
    'size' => 'md',
])

@php
    $iconClasses = match ($tone) {
        'brand' => 'bg-white/20 text-white',
        'negative' => 'bg-negative/10 text-negative',
        'positive' => 'bg-positive/10 text-positive',
        default => 'bg-accent-soft text-accent',
    };

    $valueClasses = match ($tone) {
        'brand' => 'text-white',
        'negative' => 'text-negative',
        'positive' => 'text-positive',
        default => 'text-zinc-900 dark:text-white',
    };

    $valueSize = $size === 'lg'
        ? 'text-2xl @[15rem]:text-3xl @[19rem]:text-4xl'
        : 'text-xl @[13rem]:text-2xl';

    $mutedClasses = $tone === 'brand' ? 'text-white/80' : 'text-zinc-500 dark:text-zinc-400';

    $cardClasses = $tone === 'brand'
        ? 'border-transparent bg-brand text-white shadow-lg shadow-brand/30'
        : 'border-zinc-200/70 bg-white shadow-sm shadow-zinc-900/5 dark:border-zinc-700/70 dark:bg-zinc-900 dark:shadow-none';
@endphp

<div {{ $attributes->class('@container min-w-0 rounded-2xl border p-4 sm:p-5 '.$cardClasses) }}>
    <div class="flex items-center gap-4">
        <span class="hidden size-12 flex-none items-center justify-center rounded-xl @[12rem]:flex {{ $iconClasses }}">
            <flux:icon :name="$icon" class="size-6" />
        </span>
        <div class="min-w-0 flex-1">
            <div class="text-[11px] leading-tight font-medium uppercase tracking-wide [overflow-wrap:anywhere] {{ $mutedClasses }}">{{ $label }}</div>
            <div class="mt-0.5 font-bold leading-tight tracking-tight tabular-nums {{ $valueSize }} {{ $valueClasses }}">
                {{ $slot }}
            </div>
            @isset($footer)
                <div class="mt-0.5 text-xs {{ $mutedClasses }}">{{ $footer }}</div>
            @endisset
        </div>
    </div>
</div>
