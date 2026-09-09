<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * item_cuentas, gastos, mermas, entradas y salidas se consultan por
     * cuenta_id en cada vista de detalle de cuenta y cada PDF, pero no
     * tenían índice en esa columna (con 46k+ filas en item_cuentas esto
     * era un escaneo completo de tabla en cada carga). cuentas tampoco
     * tenía un índice usable para filtros solo por fecha_venta (sin
     * sucursal_id), como hace el dashboard para administradores.
     */
    public function up(): void
    {
        Schema::table('item_cuentas', function (Blueprint $table) {
            $table->index('cuenta_id');
        });

        Schema::table('gastos', function (Blueprint $table) {
            $table->index('cuenta_id');
        });

        Schema::table('mermas', function (Blueprint $table) {
            $table->index('cuenta_id');
        });

        Schema::table('entradas', function (Blueprint $table) {
            $table->index('cuenta_id');
        });

        Schema::table('salidas', function (Blueprint $table) {
            $table->index('cuenta_id');
        });

        Schema::table('cuentas', function (Blueprint $table) {
            $table->index('fecha_venta');
        });
    }

    public function down(): void
    {
        Schema::table('item_cuentas', function (Blueprint $table) {
            $table->dropIndex(['cuenta_id']);
        });

        Schema::table('gastos', function (Blueprint $table) {
            $table->dropIndex(['cuenta_id']);
        });

        Schema::table('mermas', function (Blueprint $table) {
            $table->dropIndex(['cuenta_id']);
        });

        Schema::table('entradas', function (Blueprint $table) {
            $table->dropIndex(['cuenta_id']);
        });

        Schema::table('salidas', function (Blueprint $table) {
            $table->dropIndex(['cuenta_id']);
        });

        Schema::table('cuentas', function (Blueprint $table) {
            $table->dropIndex(['fecha_venta']);
        });
    }
};
