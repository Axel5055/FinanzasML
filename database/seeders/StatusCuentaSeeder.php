<?php

namespace Database\Seeders;

use App\Models\StatusCuenta;
use Illuminate\Database\Seeder;

class StatusCuentaSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['name' => 'PENDIENTE'],
            ['name' => 'PAGO PARCIAL'],
            ['name' => 'PAGADO'],
        ])->each(fn ($estado) => StatusCuenta::create($estado));
    }
}
