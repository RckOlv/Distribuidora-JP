<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('precios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->decimal('monto', 12, 2);
            $table->boolean('vigente')->default(true);
            $table->timestamps();
        });

        DB::statement(
            'CREATE UNIQUE INDEX precios_producto_vigente_unique ON precios (producto_id) WHERE vigente = true'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('precios');
    }
};
