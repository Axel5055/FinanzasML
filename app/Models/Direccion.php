<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Direccion extends Model
{
    /** @use HasFactory<Factory<Direccion>> */
    use HasFactory;

    protected $table = 'direcciones';

    protected $fillable = [
        'codigo_postal',
        'colonia',
        'estado',
        'numero_interior',
        'numero_exterior',
        'calle',
    ];

    /**
     * @return HasMany<Sucursal, $this>
     */
    public function sucursales(): HasMany
    {
        return $this->hasMany(Sucursal::class);
    }

    /**
     * @return Attribute<non-falsy-string, never>
     */
    public function direccionCompleta(): Attribute
    {
        return Attribute::get(function (mixed $value, array $attributes) {
            return "{$attributes['calle']} {$attributes['numero_exterior']} {$attributes['numero_interior']} {$attributes['colonia']} C.P. {$attributes['codigo_postal']} {$attributes['estado']}";
        });
    }
}
