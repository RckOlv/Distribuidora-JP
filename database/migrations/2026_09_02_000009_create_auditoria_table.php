<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla genérica de auditoría de acciones relevantes.
     *
     * Almacena qué usuario hizo qué acción sobre qué entidad, cuándo, y los
     * valores anteriores/nuevos relevantes. Nunca guarda secretos. Es
     * inmutable desde la aplicación (no hay rutas de escritura).
     */
    public function up(): void
    {
        Schema::create('auditoria', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->foreign('usuario_id')
                ->references('id')->on('usuarios')
                ->nullOnDelete();

            $table->string('accion');
            $table->string('entidad_tipo');
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->text('descripcion')->nullable();
            $table->jsonb('datos_anteriores')->nullable();
            $table->jsonb('datos_nuevos')->nullable();

            $table->timestamps();

            $table->index('usuario_id');
            $table->index(['entidad_tipo', 'entidad_id']);
            $table->index('accion');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auditoria');
    }
};
