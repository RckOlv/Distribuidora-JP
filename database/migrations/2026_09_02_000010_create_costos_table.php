<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historial de costos de los productos.
     *
     * Espejo de la tabla `precios` pero para el precio de costo. Permite
     * mantener un único costo vigente por producto conservando el historial
     * de costos anteriores. El costo puede no existir (NULL) si aún no se
     * cargó, por eso no se exige una fila por producto.
     */
    public function up(): void
    {
        Schema::create('costos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('usuario_id')
                ->nullable()
                ->constrained('usuarios')
                ->nullOnDelete();
            $table->decimal('precio', 12, 2);
            $table->boolean('vigente')->default(true);
            $table->timestamps();
        });

        DB::statement(
            'CREATE UNIQUE INDEX costos_producto_vigente_unique ON costos (producto_id) WHERE vigente = true'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('costos');
    }
};
