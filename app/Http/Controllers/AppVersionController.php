<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class AppVersionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(['version' => self::version()])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Huella de los archivos que pueden quedar desactualizados en el celular:
     * el build de Vite, el service worker y el manifest de la PWA.
     */
    public static function version(): string
    {
        $archivos = [
            public_path('build/manifest.json'),
            public_path('sw.js'),
            public_path('manifest.webmanifest'),
        ];

        $huella = collect($archivos)
            ->map(fn (string $ruta) => is_file($ruta) ? md5_file($ruta) : 'x')
            ->implode('|');

        return substr(md5($huella), 0, 12);
    }
}
