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
        Schema::create('dispositivos_impresion', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->string('token_hash', 255);
            $table->boolean('activo')->default(true);
            $table->timestamp('ultima_conexion')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dispositivos_impresion');
    }
};
