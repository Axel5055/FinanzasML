<?php

namespace Domain\Cuentas\Actions;

class ProcesarItemAction
{
    public function __invoke(array $item): array
    {
        $attributes = collect($item)->except(['producto', 'categoria_id'])->toArray();
        $precio = $item['precio'];

        $importeExistencia = (new CalcularImporte)($precio, $item['cantidad_existencia']);
        $attributes['importe_existencia'] = $importeExistencia;

        $importeEntrada = (new CalcularImporte)($precio, $item['cantidad_entrada']);
        $attributes['importe_entrada'] = $importeEntrada;

        $importeSalida = (new CalcularImporte)($precio, $item['cantidad_salida']);
        $attributes['importe_salida'] = $importeSalida;

        $importeSalida = (new CalcularImporte)($precio, $item['cantidad_sobrante']);
        $attributes['importe_sobrante'] = $importeSalida;

        return $attributes;
    }
}
