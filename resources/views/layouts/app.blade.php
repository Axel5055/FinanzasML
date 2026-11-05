<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main container>
        <div class="-mt-4 mb-2 hidden justify-end lg:flex">
            <x-theme-toggle />
        </div>

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
