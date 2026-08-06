<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Controla que un usuario solo pueda "presentar" la existencia inicial
     * del wizard de captura una vez por día (ver Registrar\Form::presentar()).
     */
    public function up(): void
    {
        Schema::create('executions', function (Blueprint $table) {
            $table->id();
            $table->string('function_name');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamp('last_executed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executions');
    }
};
