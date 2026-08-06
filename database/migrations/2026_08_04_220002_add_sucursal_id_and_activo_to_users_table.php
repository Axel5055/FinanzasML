<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Usuario deshabilitado (activo=false) no puede iniciar sesión, ver
     * FortifyServiceProvider::boot(). Conserva su historial.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales');
            $table->boolean('activo')->default(true)->after('sucursal_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sucursal_id');
            $table->dropColumn('activo');
        });
    }
};
