<?php

namespace App\Providers;

use App\Models\ActivityLog;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureTrustedProxies();
        $this->registrarSesionesEnBitacora();
    }

    /**
     * Deja constancia en la bitácora de cada inicio y cierre de sesión.
     */
    protected function registrarSesionesEnBitacora(): void
    {
        Event::listen(Login::class, fn (Login $evento) => ActivityLog::create([
            'user_id' => $evento->user->getAuthIdentifier(),
            'description' => 'inició sesión',
            'ip_address' => request()->ip(),
        ]));

        Event::listen(Logout::class, fn (Logout $evento) => $evento->user && ActivityLog::create([
            'user_id' => $evento->user->getAuthIdentifier(),
            'description' => 'cerró sesión',
            'ip_address' => request()->ip(),
        ]));
    }

    protected function configureTrustedProxies(): void
    {
        $proxies = config('app.trusted_proxies');

        if (filled($proxies)) {
            TrustProxies::at($proxies === '*' ? '*' : explode(',', $proxies));
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
