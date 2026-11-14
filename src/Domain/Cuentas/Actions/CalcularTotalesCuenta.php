<?php

namespace Domain\Cuentas\Actions;

/**
 * Fórmula única del corte de caja, usada al capturar la cuenta y al editarla desde el detalle:
 *
 *   total_venta = existencia + entradas − sobrante − salidas − gastos − mermas
 *   diferencia  = total_venta − (efectivo entregado + tarjeta)      (> 0 = faltante de caja)
 */
class CalcularTotalesCuenta
{
    /**
     * @return array{total_venta: float, diferencia: float}
     */
    public function __invoke(
        float $existencia,
        float $entradas,
        float $sobrante,
        float $salidas,
        float $gastos,
        float $mermas,
        float $efectivoEntregado,
        float $tarjeta,
    ): array {
        $totalVenta = round($existencia + $entradas - $sobrante - $salidas - $gastos - $mermas, 3);

        return [
            'total_venta' => $totalVenta,
            'diferencia' => round($totalVenta - ($efectivoEntregado + $tarjeta), 3),
        ];
    }
}
