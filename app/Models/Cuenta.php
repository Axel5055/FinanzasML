<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cuenta extends Model
{
    /** @use HasFactory<Factory<Cuenta>> */
    use HasFactory;

    protected $table = 'cuentas';

    protected $fillable = [
        'efectivo_pollo',
        'efectivo_marinado',
        'efectivo_total',
        'efectivo_entregado',
        'total_venta',
        'tarjeta',
        'diferencia',
        'sobrante',
        'fecha_captura',
        'fecha_venta',
        'sucursal_id',
        'status_cuenta_id',
    ];

    protected $attributes = [
        'status_cuenta_id' => 1,
    ];

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return HasMany<ItemCuenta, $this>
     */
    public function itemsCuenta(): HasMany
    {
        return $this->hasMany(ItemCuenta::class);
    }

    /**
     * @return HasMany<Salida, $this>
     */
    public function salidas(): HasMany
    {
        return $this->hasMany(Salida::class);
    }

    /**
     * @return HasMany<Entrada, $this>
     */
    public function entradas(): HasMany
    {
        return $this->hasMany(Entrada::class);
    }

    /**
     * @return HasMany<Gasto, $this>
     */
    public function gastos(): HasMany
    {
        return $this->hasMany(Gasto::class);
    }

    /**
     * @return HasMany<Merma, $this>
     */
    public function mermas(): HasMany
    {
        return $this->hasMany(Merma::class);
    }

    /**
     * @return BelongsTo<StatusCuenta, $this>
     */
    public function status_cuenta(): BelongsTo
    {
        return $this->belongsTo(StatusCuenta::class);
    }
}
