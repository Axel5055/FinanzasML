<?php

use App\Models\Cuenta;
use App\Models\Producto;
use App\Models\Salida;
use App\Models\Sucursal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entradas', function (Blueprint $table) {
            $table->id();
            $table->decimal('precio', 16, 3);
            $table->decimal('precio_envio', 16, 3);
            $table->decimal('cantidad', 16, 3);
            $table->decimal('total', 16, 3)->unsigned();
            $table->date('fecha_entrada');
            $table->foreignIdFor(Producto::class)->constrained();
            $table->foreignIdFor(Sucursal::class, 'sucursal_origen_id')->constrained('sucursales');
            $table->foreignIdFor(Sucursal::class, 'sucursal_destino_id')->constrained('sucursales');
            $table->foreignIdFor(Salida::class)->nullable()->constrained()->onDelete('set null')->onUpdate('cascade');
            $table->foreignIdFor(Cuenta::class)->constrained()->onDelete('cascade')->onUpdate('cascade');
            $table->softDeletes();
            $table->timestamps();

            $table->index('fecha_entrada', 'entradas_fecha_entrada_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entradas');
    }
};
