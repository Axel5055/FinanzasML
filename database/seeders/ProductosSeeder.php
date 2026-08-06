<?php

namespace Database\Seeders;

use App\Models\Producto;
use Illuminate\Database\Seeder;

class ProductosSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['name' => '*POLLO ENTERO', 'categoria_id' => 1],
            ['name' => '*PECHUGA', 'categoria_id' => 1],
            ['name' => '*PIERNA/MULOS', 'categoria_id' => 1],
            ['name' => '*PIERNA SOLA', 'categoria_id' => 1],
            ['name' => '*MUSLO', 'categoria_id' => 1],
            ['name' => '*RETAZO', 'categoria_id' => 1],
            ['name' => '*PATA', 'categoria_id' => 1],
            ['name' => '*HÍGADO', 'categoria_id' => 1],
            ['name' => '*MOLLEJA', 'categoria_id' => 1],
            ['name' => '*CABEZA', 'categoria_id' => 1],
            ['name' => '*H/R', 'categoria_id' => 1],
            ['name' => '*GALLINA', 'categoria_id' => 1],
            ['name' => '*ALA NATURAL', 'categoria_id' => 1],
            ['name' => '*HUEVO BLANCO', 'categoria_id' => 1],
            ['name' => 'HUEVO ROJO', 'categoria_id' => 1],
            ['name' => 'PATA PELADA', 'categoria_id' => 1],
            ['name' => 'PM DE TOTE', 'categoria_id' => 1],
            ['name' => 'PECHUGA DE TOTE', 'categoria_id' => 1],
            ['name' => 'ALA MARINADA', 'categoria_id' => 1],
            ['name' => 'PECHUGA CONELADA', 'categoria_id' => 1],
            ['name' => 'P/M DESHUESADA', 'categoria_id' => 1],
            ['name' => 'LONGANIZA', 'categoria_id' => 1],
            ['name' => 'MOLLEJA BLANCA', 'categoria_id' => 1],
            ['name' => 'HÍGADO AVI', 'categoria_id' => 1],
            ['name' => 'TENDER', 'categoria_id' => 2],
            ['name' => 'NUGGET', 'categoria_id' => 2],
            ['name' => 'ALITA PICOSITA', 'categoria_id' => 2],
            ['name' => 'TIRAS DE ANTOJO', 'categoria_id' => 2],
            ['name' => 'NUGGET ESTRELLA', 'categoria_id' => 2],
            ['name' => 'PALOMITA DE PECH.', 'categoria_id' => 2],
            ['name' => 'HAMBURGUESA DE POLLO', 'categoria_id' => 2],
            ['name' => 'PAPA CATERPACK', 'categoria_id' => 2],
            ['name' => 'PAPA IDAHO', 'categoria_id' => 2],
            ['name' => 'PAPA GAJO', 'categoria_id' => 2],
            ['name' => 'PAPA ESPIRAL', 'categoria_id' => 2],
            ['name' => 'MOLE', 'categoria_id' => 2],
            ['name' => 'BONELESS', 'categoria_id' => 2],
            ['name' => 'PAPA CARITA', 'categoria_id' => 2],
            ['name' => 'HAMBURGUESA DE SIRLOIN', 'categoria_id' => 2],
            ['name' => 'AROS DE CEBOLLA', 'categoria_id' => 2],
            ['name' => 'MARINADOR EN POLVO', 'categoria_id' => 2],
            ['name' => 'HAMBURGUESA DE ARRACHERA', 'categoria_id' => 2],
            ['name' => 'PAPA CÁSCARA', 'categoria_id' => 2],
            ['name' => 'TILAPIA', 'categoria_id' => 2],
            ['name' => 'SALMÓN', 'categoria_id' => 2],
            ['name' => 'ARRACHERA', 'categoria_id' => 2],
            ['name' => 'DEDOS DE QUESO', 'categoria_id' => 2],
            ['name' => 'PIERNA DE PAVO AHUMADA', 'categoria_id' => 2],
            ['name' => 'POLLO NAVIDEÑO', 'categoria_id' => 2],
            ['name' => 'PAVO NATURAL', 'categoria_id' => 2],
            ['name' => 'PAVO AHUMADO', 'categoria_id' => 2],
            ['name' => 'TITAS GRILL', 'categoria_id' => 2],
            ['name' => 'MC NUGGET', 'categoria_id' => 2],
            ['name' => 'PAPA CANOA', 'categoria_id' => 2],
            ['name' => 'CALAMAR', 'categoria_id' => 2],
            ['name' => 'ATÚN', 'categoria_id' => 2],
            ['name' => 'BOLITAS DE QUESO', 'categoria_id' => 2],
            ['name' => 'BRACITOS', 'categoria_id' => 2],
            ['name' => 'PAPA EMOTICON', 'categoria_id' => 2],
            ['name' => 'CAMARÓN', 'categoria_id' => 2],
            ['name' => 'NUGGET DINO', 'categoria_id' => 2],
            ['name' => 'NACHO', 'categoria_id' => 2],
            ['name' => 'PAPAEMOTICON', 'categoria_id' => 2],
        ])->each(fn ($producto) => Producto::create($producto));
    }
}
