<div
    x-data="{
        actualizando: false,
        avisar(texto, variante = 'success') {
            document.dispatchEvent(new CustomEvent('toast-show', {
                detail: { duration: 5000, slots: { text: texto }, dataset: { variant: variante } },
            }));
        },
        avisarSiSeActualizo() {
            try {
                if (sessionStorage.getItem('app-actualizada')) {
                    sessionStorage.removeItem('app-actualizada');
                    setTimeout(() => this.avisar('Aplicación actualizada correctamente.', 'success'), 600);
                }
            } catch (error) {}
        },
        async limpiarYRecargar() {
            try {
                if ('serviceWorker' in navigator) {
                    const registros = await navigator.serviceWorker.getRegistrations();
                    await Promise.all(registros.map((registro) => registro.unregister()));
                }

                if ('caches' in window) {
                    const claves = await caches.keys();
                    await Promise.all(claves.map((clave) => caches.delete(clave)));
                }
            } catch (error) {
                // Si el navegador no permite limpiar, la recarga normal sigue funcionando.
            }

            try {
                sessionStorage.setItem('app-actualizada', '1');
            } catch (error) {}

            window.location.reload();
        },
        async actualizar() {
            this.actualizando = true;

            try {
                const respuesta = await fetch('{{ route('app-version') }}', { cache: 'no-store' });
                const { version } = await respuesta.json();
                const versionActual = document.querySelector('meta[name=app-version]')?.content;

                if (version === versionActual) {
                    this.avisar('Ya estás usando la versión más actual.', 'success');
                    this.actualizando = false;

                    return;
                }

                await this.limpiarYRecargar();
            } catch (error) {
                this.avisar('No se pudo buscar actualizaciones. Revisa tu conexión.', 'danger');
                this.actualizando = false;
            }
        },
    }"
    x-init="avisarSiSeActualizo()"
    class="text-center"
>
    <button
        type="button"
        x-on:click="actualizar()"
        x-bind:disabled="actualizando"
        class="text-sm text-zinc-500 underline underline-offset-2 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200 disabled:opacity-60"
        data-test="update-app-link"
    >
        <span x-show="! actualizando">Actualizar aplicación</span>
        <span x-show="actualizando" style="display: none">Buscando actualización…</span>
    </button>
</div>
