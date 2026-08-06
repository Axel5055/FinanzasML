<?php

use App\Models\Categoria;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            $table->foreignIdFor(Categoria::class)
                ->constrained('categorias')
                ->onDelete('restrict')
                ->onUpdate('cascade');

            // Precio "maestro" del producto, independiente del historial diario en
            // item_cuentas. Se aplica manualmente vía el botón "Aplicar nuevos precios".
            $table->decimal('precio', 16, 3)->unsigned()->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
