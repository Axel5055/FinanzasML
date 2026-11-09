<?php

use Domain\Cuentas\Actions\ProcesarItemAction;

test('los campos numericos vacios se tratan como cero y no rompen el calculo', function () {
    $resultado = (new ProcesarItemAction)([
        'producto_id' => 7,
        'producto' => 'PECHUGA',
        'categoria_id' => 1,
        'precio' => '65',
        'cantidad_existencia' => '10',
        'cantidad_entrada' => '',
        'cantidad_salida' => '',
        'cantidad_sobrante' => null,
    ]);

    expect($resultado['importe_existencia'])->toBe(650.0)
        ->and($resultado['importe_entrada'])->toBe(0.0)
        ->and($resultado['importe_salida'])->toBe(0.0)
        ->and($resultado['importe_sobrante'])->toBe(0.0)
        ->and($resultado['cantidad_entrada'])->toBe(0.0)
        ->and($resultado['cantidad_sobrante'])->toBe(0.0)
        ->and($resultado)->not->toHaveKeys(['producto', 'categoria_id']);
});

test('los importes se calculan con precio por cantidad redondeado a tres decimales', function () {
    $resultado = (new ProcesarItemAction)([
        'producto_id' => 1,
        'precio' => '12.5',
        'cantidad_existencia' => '1.2345',
        'cantidad_entrada' => '2',
        'cantidad_salida' => '0',
        'cantidad_sobrante' => '3.333',
    ]);

    expect($resultado['importe_existencia'])->toBe(15.431)
        ->and($resultado['importe_entrada'])->toBe(25.0)
        ->and($resultado['importe_sobrante'])->toBe(41.663);
});
