<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asocia cada venta a la caja (sesión) abierta en la que se registró.
     */
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->foreignId('caja_id')->nullable()->constrained('cajas')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('caja_id');
        });
    }
};