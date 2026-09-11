<?php

namespace Database\Seeders;

use App\Models\Sucursal;
use Illuminate\Database\Seeder;

class SucursalesSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['id' => 2, 'name' => 'CARMEN SERDAN', 'direccion_id' => 1],
            ['id' => 3, 'name' => 'EL VERDE', 'direccion_id' => 1],
            ['id' => 4, 'name' => 'BODEGA', 'direccion_id' => 1],
            ['id' => 5, 'name' => 'ALBERTO', 'direccion_id' => 2],
            ['id' => 6, 'name' => 'apaches', 'direccion_id' => 3],
            ['id' => 7, 'name' => 'camion', 'direccion_id' => 4],
            ['id' => 8, 'name' => 'vista hermosa', 'direccion_id' => 5],
            ['id' => 9, 'name' => 'ESCUADRON 201', 'direccion_id' => 6],
        ])->each(fn ($sucursal) => Sucursal::firstOrCreate(['id' => $sucursal['id']], $sucursal));
    }
}
