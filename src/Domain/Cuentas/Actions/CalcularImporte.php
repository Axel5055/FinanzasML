<?php

namespace Domain\Cuentas\Actions;

class CalcularImporte
{
    public function __invoke(float $precio, float $cantidad): float
    {
        return round($precio * $cantidad, 3);
    }
}
