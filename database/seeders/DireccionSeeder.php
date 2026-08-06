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
        ])->each(fn ($direccion) => Direccion::create($direccion));
    }
}
