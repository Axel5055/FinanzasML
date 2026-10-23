<?php

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

afterEach(function () {
    TrustProxies::flushState();
    Request::setTrustedProxies([], 0);
});

function peticionDesdeProxy($test)
{
    return $test->withServerVariables(['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'demo.ngrok-free.app'])
        ->get(route('login'));
}

test('sin TRUSTED_PROXIES se ignoran las cabeceras X-Forwarded', function () {
    peticionDesdeProxy($this)
        ->assertOk()
        ->assertDontSee('demo.ngrok-free.app', false);
});

test('con TRUSTED_PROXIES las peticiones del proxy se tratan como https', function () {
    config(['app.trusted_proxies' => '*']);
    (new AppServiceProvider(app()))->boot();

    peticionDesdeProxy($this)
        ->assertOk()
        ->assertSee('https://demo.ngrok-free.app/build/assets', false);
});
