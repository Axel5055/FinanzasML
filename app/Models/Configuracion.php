<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Configuracion extends Model
{
    const IA_CAPTURA_HABILITADA = 'ia_captura_habilitada';

    protected $table = 'configuraciones';

    protected $fillable = [
        'clave',
        'valor',
    ];

    public static function activa(string $clave, bool $default = false): bool
    {
        $valor = static::where('clave', $clave)->value('valor');

        return $valor === null ? $default : $valor === '1';
    }

    public static function activar(string $clave, bool $valor): void
    {
        static::updateOrCreate(['clave' => $clave], ['valor' => $valor ? '1' : '0']);
    }
}
