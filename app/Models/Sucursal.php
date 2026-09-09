<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sucursal extends Model
{
    /** @use HasFactory<Factory<Sucursal>> */
    use HasFactory;

    protected $table = 'sucursales';

    protected $fillable = [
        'name',
        'direccion_id',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    /**
     * @param  Builder<Sucursal>  $query
     * @return Builder<Sucursal>
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * @return BelongsTo<Direccion, $this>
     */
    public function direccion(): BelongsTo
    {
        return $this->belongsTo(Direccion::class);
    }

    /**
     * @return HasMany<Cuenta, $this>
     */
    public function cuentas(): HasMany
    {
        return $this->hasMany(Cuenta::class);
    }

    /**
     * @return HasMany<Salida, $this>
     */
    public function salidas(): HasMany
    {
        return $this->hasMany(Salida::class, 'sucursal_destino_id');
    }
}
