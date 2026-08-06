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
        ])->each(fn ($sucursal) => Sucursal::create($sucursal));
    }
}
