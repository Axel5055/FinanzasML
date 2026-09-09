<?php

use App\Livewire\CuentaTable;
use App\Models\Direccion;
use App\Models\Sucursal;

test('el filtro de sucursal de la tabla de cuentas excluye sucursales inactivas', function () {
    $direccion = Direccion::create([
        'codigo_postal' => '09837',
        'colonia' => 'San Miguel',
        'estado' => 'CDMX',
        'numero_interior' => 'S/N',
        'numero_exterior' => 'S/N',
        'calle' => 'Calle 1',
    ]);

    Sucursal::create(['name' => 'CARMEN SERDAN', 'direccion_id' => $direccion->id, 'activo' => true]);
    Sucursal::create(['name' => 'NINGUNA', 'direccion_id' => $direccion->id, 'activo' => false]);

    $filtro = collect((new CuentaTable)->filters())->firstWhere('column', 'sucursal_id');

    expect($filtro)->not->toBeNull();

    $nombres = $filtro->dataSource->pluck('name');

    expect($nombres)->toContain('CARMEN SERDAN')
        ->and($nombres)->not->toContain('NINGUNA');
});
