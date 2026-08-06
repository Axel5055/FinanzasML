<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cuenta extends Model
{
    use HasFactory;

    protected $table = 'cuentas';

    protected $fillable = [
        'efectivo_pollo',
        'efectivo_marinado',
        'efectivo_total',
        'efectivo_entregado',
        'total_venta',
        'transferencia',
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

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function itemsCuenta(): HasMany
    {
        return $this->hasMany(ItemCuenta::class);
    }

    public function salidas(): HasMany
    {
        return $this->hasMany(Salida::class);
    }

    public function entradas(): HasMany
    {
        return $this->hasMany(Entrada::class);
    }

    public function gastos(): HasMany
    {
        return $this->hasMany(Gasto::class);
    }

    public function mermas(): HasMany
    {
        return $this->hasMany(Merma::class);
    }

    public function status_cuenta(): BelongsTo
    {
        return $this->belongsTo(StatusCuenta::class);
    }
}
