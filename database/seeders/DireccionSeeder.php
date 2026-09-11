<?php

namespace Database\Seeders;

use App\Models\Direccion;
use Illuminate\Database\Seeder;

class DireccionSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['id' => 1, 'codigo_postal' => '09837', 'colonia' => 'San Miguel', 'estado' => 'Ciudad de México', 'numero_interior' => 'S/N', 'numero_exterior' => 'S/N', 'calle' => '8va Amp San Miguel'],
            ['id' => 2, 'codigo_postal' => '05120', 'colonia' => 'Bosques de las Lomas', 'estado' => 'Ciudad de México', 'numero_interior' => 'S/N', 'numero_exterior' => '90', 'calle' => 'Av. Paseo de los Tamarindos'],
            ['id' => 3, 'codigo_postal' => '05120', 'colonia' => 'Bosque de las Lomas', 'estado' => 'Ciudad de México', 'numero_interior' => '50', 'numero_exterior' => '50', 'calle' => 'Av. Paseo de los Tamarindos'],
            ['id' => 4, 'codigo_postal' => '05120', 'colonia' => 'Bosque de las Lomas', 'estado' => 'Ciudad de México', 'numero_interior' => '50', 'numero_exterior' => '90', 'calle' => 'Av. Paseo de los Tamarindos'],
            ['id' => 5, 'codigo_postal' => '05120', 'colonia' => 'Bosque de las Lomas', 'estado' => 'Ciudad de México', 'numero_interior' => '50', 'numero_exterior' => '90', 'calle' => 'Av. Paseo de los Tamarindos'],
            ['id' => 6, 'codigo_postal' => '09060', 'colonia' => 'Escuadrón 201', 'estado' => 'CDMX', 'numero_interior' => '1', 'numero_exterior' => '423', 'calle' => 'Fausto Vega'],
        ])->each(fn ($direccion) => Direccion::firstOrCreate(['id' => $direccion['id']], $direccion));
    }
}
