<?php

use App\Models\Cuenta;
use App\Models\Sucursal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mermas', function (Blueprint $table) {
            $table->id();
            $table->decimal('precio', 16, 3);
            $table->string('concepto');
            $table->foreignIdFor(Sucursal::class)->constrained('sucursales');
            $table->foreignIdFor(Cuenta::class)
                ->constrained()
                ->onDelete('cascade')
                ->onUpdate('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mermas');
    }
};
