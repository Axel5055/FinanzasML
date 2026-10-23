<?php

test('la pagina de login enlaza el manifest y registra el service worker', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('rel="manifest" href="/manifest.webmanifest"', false)
        ->assertSee('name="theme-color"', false)
        ->assertSee("serviceWorker.register('/sw.js')", false);
});

test('el manifest es valido y sus iconos existen', function () {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)
        ->toHaveKeys(['name', 'short_name', 'start_url', 'display', 'icons'])
        ->and($manifest['display'])->toBe('standalone');

    foreach ($manifest['icons'] as $icono) {
        expect(public_path(ltrim($icono['src'], '/')))->toBeFile();
    }

    expect(public_path('sw.js'))->toBeFile()
        ->and(public_path('offline.html'))->toBeFile();
});

test('el login muestra el boton de instalar aplicacion y soporta modo oscuro', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('data-test="install-app-button"', false)
        ->assertSee('data-test="update-app-link"', false)
        ->assertSee('beforeinstallprompt', false)
        ->assertSee('dark:bg-zinc-900', false)
        ->assertSee('img/logo-white.svg', false);
});

test('el endpoint de version responde sin cache y coincide con la version de la pagina', function () {
    $respuesta = $this->get(route('app-version'))->assertOk();

    $version = $respuesta->json('version');

    expect($respuesta->headers->get('Cache-Control'))->toContain('no-store')
        ->and($version)->toBeString()->not->toBeEmpty();

    $this->get(route('login'))->assertSee('<meta name="app-version" content="'.$version.'">', false);
});
