<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen antialiased">
        <div class="relative min-h-dvh flex flex-col items-center justify-center gap-6 p-6">
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('{{ asset('img/background.jpg') }}')"></div>
            <div class="absolute inset-0 bg-black/15"></div>

            <div class="relative z-10 w-full max-w-[22rem] rounded-2xl bg-white shadow-2xl p-7">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-1 mb-5" wire:navigate>
                    <img src="{{ asset('img/logo.svg') }}" alt="{{ config('app.name', 'Laravel') }}" class="h-10 w-auto" />
                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </a>

                <div class="flex flex-col gap-6">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
