<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Producto extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'productos';

    protected $fillable = [
        'name',
        'categoria_id',
        'precio',
    ];

    public function name(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => strtoupper(trim($value))
        );
    }

    public function itemsCuenta(): HasMany
    {
        return $this->hasMany(ItemCuenta::class);
    }

    public function itemCuenta(): HasOne
    {
        return $this->hasOne(ItemCuenta::class)->latestOfMany();
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function salidas(): HasMany
    {
        return $this->hasMany(Salida::class);
    }

    public function entradas(): HasMany
    {
        return $this->hasMany(Entrada::class);
    }

    public function tieneRegistrosAsociados(): bool
    {
        return $this->itemsCuenta()->exists()
            || $this->salidas()->exists()
            || $this->entradas()->exists();
    }
}
