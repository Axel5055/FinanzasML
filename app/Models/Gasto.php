<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Gasto extends Model
{
    /** @use HasFactory<Factory<Gasto>> */
    use HasFactory;

    protected $fillable = [
        'precio',
        'concepto',
        'sucursal_id',
        'cuenta_id',
    ];

    /**
     * @return Attribute<string, string>
     */
    public function concepto(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => strtoupper(trim($value)),
        );
    }

    /**
     * @return BelongsTo<Cuenta, $this>
     */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }
}
