<?php

use App\Models\Cuenta;
use App\Models\Producto;
use App\Models\Sucursal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salidas', function (Blueprint $table) {
            $table->id();

            $table->decimal('precio', 16, 3)->unsigned();
            $table->decimal('cantidad', 16, 3)->unsigned();
            $table->decimal('total', 16, 3)->unsigned();
            $table->date('fecha_salida');

            $table->foreignIdFor(Producto::class)
                ->constrained('productos')
                ->onDelete('restrict')
                ->onUpdate('cascade');

            $table->foreignIdFor(Cuenta::class)
                ->constrained('cuentas')
                ->onDelete('cascade')
                ->onUpdate('cascade');

            $table->foreignIdFor(Sucursal::class, 'sucursal_destino_id')
                ->constrained('sucursales')
                ->onDelete('restrict')
                ->onUpdate('cascade');

            $table->foreignIdFor(Sucursal::class, 'sucursal_origen_id')
                ->constrained('sucursales')
                ->onDelete('restrict')
                ->onUpdate('cascade');

            $table->timestamps();

            $table->index(['sucursal_origen_id', 'fecha_salida'], 'salidas_sucursal_origen_fecha_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salidas');
    }
};
