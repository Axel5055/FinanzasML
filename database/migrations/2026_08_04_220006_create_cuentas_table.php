<?php

use App\Models\StatusCuenta;
use App\Models\Sucursal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas', function (Blueprint $table) {
            $table->id();

            $table->decimal('efectivo_pollo', 16, 3)->unsigned();
            $table->decimal('efectivo_marinado', 16, 3)->unsigned();
            $table->decimal('efectivo_total', 16, 3)->unsigned();
            $table->decimal('efectivo_entregado', 16, 3)->unsigned();
            // Signed: puede quedar negativo cuando los egresos (salidas + gastos +
            // mermas) superan la existencia + entrada del día, lo cual representa
            // un faltante de caja real (ver recalcularTotales() en Cuentas\Show).
            $table->decimal('total_venta', 16, 3);
            $table->decimal('transferencia', 16, 3)->unsigned();
            $table->decimal('diferencia', 16, 3);
            $table->decimal('sobrante', 16, 3)->unsigned();

            $table->date('fecha_captura')->default(now()->format('Y-m-d'));
            $table->date('fecha_venta')->default(now()->format('Y-m-d'));

            $table->foreignIdFor(Sucursal::class)
                ->constrained('sucursales')
                ->onDelete('restrict')
                ->onUpdate('cascade');

            $table->foreignIdFor(StatusCuenta::class)
                ->constrained('status_cuentas')
                ->onDelete('restrict')
                ->onUpdate('cascade');

            $table->timestamps();

            $table->index(['sucursal_id', 'fecha_venta'], 'cuentas_sucursal_fecha_venta_index');
            $table->index('fecha_captura', 'cuentas_fecha_captura_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuentas');
    }
};
