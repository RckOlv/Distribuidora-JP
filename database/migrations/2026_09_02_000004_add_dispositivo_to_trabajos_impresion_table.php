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
        Schema::table('trabajos_impresion', function (Blueprint $table) {
            // Dispositivo que tomó (claim) el trabajo y cuándo comenzó.
            $table->foreignId('dispositivo_id')
                ->nullable()
                ->after('ticket_id')
                ->constrained('dispositivos_impresion');
            $table->timestamp('procesando_at')->nullable()->after('dispositivo_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trabajos_impresion', function (Blueprint $table) {
            $table->dropForeign(['dispositivo_id']);
            $table->dropColumn(['dispositivo_id', 'procesando_at']);
        });
    }
};
