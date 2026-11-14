<?php

namespace App\Models;

use Database\Factories\CategoriaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categoria extends Model
{
    /** @use HasFactory<CategoriaFactory> */
    use HasFactory;

    /** Ids fijos que crea `CategoriasSeeder`; el corte de caja separa el efectivo de pollo y de marinado. */
    public const POLLO = 1;

    public const MARINADO = 2;

    protected $table = 'categorias';

    protected $fillable = [
        'name',
    ];

    /**
     * @return HasMany<Producto, $this>
     */
    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class);
    }
}
