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
        Schema::table('ventas', function (Blueprint $table) {
            // Monto efectivo recibido y vueltto de las ventas en EFECTIVO.
            // Nulos en ventas de otros medios y en históricos previos.
            $table->decimal('efectivo_recibido', 12, 2)->nullable()->after('total');
            $table->decimal('vuelto', 12, 2)->nullable()->after('efectivo_recibido');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn(['efectivo_recibido', 'vuelto']);
        });
    }
};