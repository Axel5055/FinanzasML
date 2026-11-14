<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un producto solo puede aparecer una vez por cuenta. Antes de crear el índice se limpian los
     * renglones repetidos que dejaron capturas anteriores: de cada grupo se conserva el que tiene
     * más movimiento (existencia + entrada + sobrante) y, a igualdad, el más reciente.
     *
     * También se quita el DEFAULT de `fecha_venta`: la migración original lo dejó fijo con la
     * fecha del día en que se corrió. La aplicación siempre envía la fecha.
     */
    public function up(): void
    {
        DB::table('item_cuentas')
            ->select('cuenta_id', 'producto_id')
            ->groupBy('cuenta_id', 'producto_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('cuenta_id')
            ->get()
            ->each(function (object $grupo): void {
                $conservar = DB::table('item_cuentas')
                    ->where('cuenta_id', $grupo->cuenta_id)
                    ->where('producto_id', $grupo->producto_id)
                    ->orderByRaw('(importe_existencia + importe_entrada + importe_sobrante) DESC')
                    ->orderByDesc('id')
                    ->value('id');

                DB::table('item_cuentas')
                    ->where('cuenta_id', $grupo->cuenta_id)
                    ->where('producto_id', $grupo->producto_id)
                    ->where('id', '!=', $conservar)
                    ->delete();
            });

        Schema::table('item_cuentas', function (Blueprint $table) {
            $table->unique(['cuenta_id', 'producto_id'], 'item_cuentas_cuenta_producto_unique');
            $table->date('fecha_venta')->change();
        });
    }

    public function down(): void
    {
        Schema::table('item_cuentas', function (Blueprint $table) {
            $table->dropUnique('item_cuentas_cuenta_producto_unique');
        });
    }
};
