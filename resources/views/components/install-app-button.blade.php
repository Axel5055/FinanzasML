<div
    x-data="{
        prompt: window.deferredInstallPrompt ?? null,
        instalada: window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true,
        esIos: /iphone|ipad|ipod/i.test(navigator.userAgent),
        async instalar() {
            if (! this.prompt) {
                return;
            }

            this.prompt.prompt();
            await this.prompt.userChoice;
            this.prompt = null;
        },
    }"
    x-on:pwa-install-available.window="prompt = window.deferredInstallPrompt"
    x-on:pwa-installed.window="instalada = true"
    x-show="! instalada && (prompt || esIos)"
    style="display: none"
    class="flex flex-col gap-2"
>
    <flux:separator />

    <flux:button x-show="prompt" x-on:click="instalar()" icon="arrow-down-tray" class="w-full" data-test="install-app-button">
        Instalar aplicación
    </flux:button>

    <p x-show="! prompt && esIos" class="text-center text-sm text-zinc-500 dark:text-zinc-400">
        Para instalar la app toca <strong>Compartir</strong> y luego <strong>Agregar a pantalla de inicio</strong>.
    </p>
</div>
