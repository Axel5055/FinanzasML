<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

{{-- flatpickr debe existir como global ANTES de que Livewire/Alpine arranquen (PowerGrid lo usa para su filtro de fecha) --}}
<script src="{{ asset('vendor/flatpickr/flatpickr.min.js') }}"></script>

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
