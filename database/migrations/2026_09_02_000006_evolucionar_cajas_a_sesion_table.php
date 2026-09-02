<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Evoluciona `cajas` de un catálogo de terminales a una sesión de caja.
     *
     * La tabla existente y sus movimientos se reutilizan (no se crea una
     * estructura paralela). Se reemplazan las columnas de "terminal" (nombre
     * único, activa) por las de una sesión: quien la abre, monto inicial,
     * estado, fechas y los datos persistentes del cierre.
     */
    public function up(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            $table->dropUnique(['nombre']);
            $table->dropColumn('nombre');
            $table->dropColumn('activa');

            $table->foreignId('usuario_abre_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('estado')->default('ABIERTA');
            $table->decimal('monto_inicial', 12, 2)->default(0);
            $table->timestamp('abierta_en')->nullable();
            $table->timestamp('cerrada_en')->nullable();
            $table->decimal('efectivo_contado', 12, 2)->nullable();
            $table->decimal('efectivo_esperado', 12, 2)->nullable();
            $table->decimal('diferencia', 12, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            $table->dropColumn([
                'usuario_abre_id',
                'estado',
                'monto_inicial',
                'abierta_en',
                'cerrada_en',
                'efectivo_contado',
                'efectivo_esperado',
                'diferencia',
            ]);

            $table->string('nombre')->unique();
            $table->boolean('activa')->default(true);
        });
    }
};