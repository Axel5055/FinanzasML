<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Entrada extends Model
{
    /** @use HasFactory<Factory<Entrada>> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'precio',
        'precio_envio',
        'cantidad',
        'fecha_entrada',
        'producto_id',
        'sucursal_origen_id',
        'sucursal_destino_id',
        'salida_id',
        'cuenta_id',
        'total',
    ];

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursalDestino(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_destino_id');
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursalOrigen(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_origen_id');
    }

    /**
     * @return BelongsTo<Producto, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * @return BelongsTo<Salida, $this>
     */
    public function salida(): BelongsTo
    {
        return $this->belongsTo(Salida::class);
    }

    /**
     * @return BelongsTo<Cuenta, $this>
     */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }
}
