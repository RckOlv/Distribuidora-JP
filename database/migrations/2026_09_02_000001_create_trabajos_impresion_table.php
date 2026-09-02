<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trabajos_impresion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')
                ->constrained('tickets')
                ->cascadeOnDelete();
            $table->string('estado', 20)->default('PENDIENTE');
            $table->unsignedInteger('cantidad_intentos')->default(0);
            $table->text('ultimo_error')->nullable();
            $table->timestamp('impreso_en')->nullable();
            $table->timestamps();

            // Un trabajo por ticket.
            $table->unique('ticket_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trabajos_impresion');
    }
};
