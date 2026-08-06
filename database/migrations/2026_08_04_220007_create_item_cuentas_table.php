<?php

use App\Models\Cuenta;
use App\Models\Producto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_cuentas', function (Blueprint $table) {
            $table->id();

            $table->decimal('precio', 16, 3)->unsigned();
            $table->decimal('cantidad_existencia', 16, 3)->unsigned();
            $table->decimal('importe_existencia', 16, 3)->unsigned();
            $table->decimal('cantidad_entrada', 16, 3)->unsigned();
            $table->decimal('importe_entrada', 16, 3)->unsigned();
            $table->decimal('cantidad_salida', 16, 3)->unsigned();
            $table->decimal('importe_salida', 16, 3)->unsigned();
            $table->decimal('cantidad_sobrante', 16, 3)->unsigned();
            $table->decimal('importe_sobrante', 16, 3)->unsigned();
            $table->decimal('cantidad_mayoreo', 16, 3)->unsigned();
            $table->decimal('importe_mayoreo', 16, 3)->unsigned();

            $table->foreignIdFor(Producto::class)
                ->constrained('productos')
                ->onDelete('restrict')
                ->onUpdate('cascade');

            $table->foreignIdFor(Cuenta::class)
                ->constrained('cuentas')
                ->onDelete('cascade')
                ->onUpdate('cascade');

            $table->date('fecha_venta')->default(now()->format('Y-m-d'));

            $table->timestamps();

            $table->index('fecha_venta', 'item_cuentas_fecha_venta_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_cuentas');
    }
};
