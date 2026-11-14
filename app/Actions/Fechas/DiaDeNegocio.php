<?php

namespace App\Actions\Fechas;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * El sistema guarda las marcas de tiempo en UTC, pero el negocio opera en hora de México:
 * "hoy", "esta semana" o "este mes" se miden con este huso para que el día no cambie a las 6 pm.
 */
class DiaDeNegocio
{
    public static function zona(): string
    {
        return (string) config('app.business_timezone', 'America/Mexico_City');
    }

    public static function ahora(): CarbonImmutable
    {
        return Date::now(self::zona());
    }

    public static function hoy(): CarbonImmutable
    {
        return self::ahora()->startOfDay();
    }

    /**
     * Inicio del día de negocio indicado, expresado en UTC (para comparar contra columnas timestamp).
     */
    public static function inicioUtc(?CarbonImmutable $dia = null): CarbonImmutable
    {
        return ($dia ?? self::hoy())->startOfDay()->utc();
    }

    public static function finUtc(?CarbonImmutable $dia = null): CarbonImmutable
    {
        return ($dia ?? self::hoy())->endOfDay()->utc();
    }
}
