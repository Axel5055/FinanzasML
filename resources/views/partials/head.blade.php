<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">

<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#6c63e8">
<meta name="app-version" content="{{ \App\Http\Controllers\AppVersionController::version() }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="ML Grupo">
<script>
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        window.deferredInstallPrompt = event;
        window.dispatchEvent(new CustomEvent('pwa-install-available'));
    });

    window.addEventListener('appinstalled', () => {
        window.deferredInstallPrompt = null;
        window.dispatchEvent(new CustomEvent('pwa-installed'));
    });

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
    }
</script>

@fonts

{{-- flatpickr debe existir como global ANTES de que Livewire/Alpine arranquen (PowerGrid lo usa para su filtro de fecha) --}}
<script src="{{ asset('vendor/flatpickr/flatpickr.min.js') }}"></script>

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
