@props([
    'title',
    'subtitle' => null,
    'icon' => null,
])

<section {{ $attributes->class('rounded-2xl border border-zinc-200/70 bg-white shadow-sm shadow-zinc-900/5 dark:border-zinc-700/70 dark:bg-zinc-900 dark:shadow-none') }}>
    <header class="px-5 pt-5">
        @if ($icon)
            <x-page-header :icon="$icon" :title="$title" :subtitle="$subtitle" size="md">
                @isset($actions)
                    {{ $actions }}
                @endisset
            </x-page-header>
        @else
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $title }}</h2>
                    @if ($subtitle)
                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $subtitle }}</p>
                    @endif
                </div>
                @isset($actions)
                    <div class="flex-none">{{ $actions }}</div>
                @endisset
            </div>
        @endif
    </header>
    <div class="p-5 pt-4">
        {{ $slot }}
    </div>
</section>
