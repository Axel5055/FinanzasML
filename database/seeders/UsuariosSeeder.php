<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class UsuariosSeeder extends Seeder
{
    public function run(): void
    {
        $usuarios = [
            ['name' => 'Ethan', 'email' => 'ethan@mlgrupo.com.mx', 'sucursal_id' => null, 'role' => 'Super Admin'],
            ['name' => 'Alberto', 'email' => 'alberto@mlgrupo.com.mx', 'sucursal_id' => null, 'role' => 'Admin'],
            ['name' => 'CARMEN SERDAN', 'email' => 'carmenserdan@mlgrupo.com.mx', 'sucursal_id' => 2, 'role' => 'Capturista'],
            ['name' => 'EL VERDE', 'email' => 'elverde@mlgrupo.com.mx', 'sucursal_id' => 2, 'role' => 'Capturista'],
        ];

        foreach ($usuarios as $datos) {
            $password = Str::password(12);

            User::create([
                'name' => $datos['name'],
                'email' => $datos['email'],
                'password' => $password,
                'sucursal_id' => $datos['sucursal_id'],
            ])->assignRole($datos['role']);

            $this->command->info("Usuario {$datos['email']} creado con contraseña temporal: {$password}");
        }
    }
}
