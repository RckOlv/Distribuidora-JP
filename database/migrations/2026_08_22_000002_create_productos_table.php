<?php

use App\Enums\UnidadVenta;
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
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->constrained('categorias')->restrictOnDelete();
            $table->string('codigo', 64)->nullable()->unique();
            $table->string('nombre');
            $table->string('descripcion')->nullable();
            $table->string('unidad_medida')->default(UnidadVenta::UNIDAD->value);
            $table->decimal('stock_minimo', 12, 3)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
