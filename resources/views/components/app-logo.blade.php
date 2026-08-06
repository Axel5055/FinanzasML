@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :alt="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="h-9 min-w-9">
            <img src="{{ asset('img/logo.svg') }}" alt="{{ config('app.name', 'Laravel') }}" class="h-9 w-auto dark:hidden" />
            <img src="{{ asset('img/logo-white.svg') }}" alt="{{ config('app.name', 'Laravel') }}" class="h-9 w-auto hidden dark:block" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :alt="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="h-9 min-w-9">
            <img src="{{ asset('img/logo.svg') }}" alt="{{ config('app.name', 'Laravel') }}" class="h-9 w-auto dark:hidden" />
            <img src="{{ asset('img/logo-white.svg') }}" alt="{{ config('app.name', 'Laravel') }}" class="h-9 w-auto hidden dark:block" />
        </x-slot>
    </flux:brand>
@endif
