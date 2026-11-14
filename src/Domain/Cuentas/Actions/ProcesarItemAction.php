<?php

namespace Domain\Cuentas\Actions;

class ProcesarItemAction
{
    /**
     * Calcula los importes de un producto de la cuenta.
     *
     * Los campos numéricos del formulario llegan como texto y vacíos ('') cuando
     * el capturista borra el valor; se normalizan a número (vacío = 0) para no
     * romper el cálculo ni guardar un texto vacío en columnas decimales.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function __invoke(array $item): array
    {
        // Lista blanca: del arreglo que llega del navegador solo se usan columnas de importes/cantidades y el producto.
        $attributes = collect($item)->only([
            'producto_id',
            'precio',
            'cantidad_existencia', 'importe_existencia',
            'cantidad_entrada', 'importe_entrada',
            'cantidad_salida', 'importe_salida',
            'cantidad_sobrante', 'importe_sobrante',
            'cantidad_mayoreo', 'importe_mayoreo',
        ])->toArray();

        foreach ($attributes as $campo => $valor) {
            if ($campo === 'precio' || str_starts_with($campo, 'cantidad_') || str_starts_with($campo, 'importe_')) {
                $attributes[$campo] = $this->aNumero($valor);
            }
        }

        $precio = $this->aNumero($attributes['precio'] ?? 0);

        $attributes['importe_existencia'] = (new CalcularImporte)($precio, $this->aNumero($attributes['cantidad_existencia'] ?? 0));
        $attributes['importe_entrada'] = (new CalcularImporte)($precio, $this->aNumero($attributes['cantidad_entrada'] ?? 0));
        $attributes['importe_salida'] = (new CalcularImporte)($precio, $this->aNumero($attributes['cantidad_salida'] ?? 0));
        $attributes['importe_sobrante'] = (new CalcularImporte)($precio, $this->aNumero($attributes['cantidad_sobrante'] ?? 0));

        return $attributes;
    }

    private function aNumero(mixed $valor): float
    {
        if (is_string($valor)) {
            $valor = str_replace(',', '', trim($valor));
        }

        return is_numeric($valor) ? (float) $valor : 0.0;
    }
}
