<?php

namespace Database\Seeders;

use App\Models\Categoria;
use Illuminate\Database\Seeder;

class CategoriasSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['name' => 'Pollo'],
            ['name' => 'Marinado'],
        ])->each(fn ($categoria) => Categoria::create($categoria));
    }
}
